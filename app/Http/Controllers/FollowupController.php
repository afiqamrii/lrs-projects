<?php

namespace App\Http\Controllers;

use App\Actions\ManageFollowups;
use App\Http\Requests\FollowupPolicyRequest;
use App\Models\AttentionTask;
use App\Models\ClientQuotationApproval;
use App\Models\FollowupPlan;
use App\Models\FollowupPolicy;
use App\Models\FollowupStage;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FollowupController extends Controller
{
    public function settings(Request $r): View
    {
        $kind = $r->validate(['kind' => ['nullable', Rule::in(['rfq', 'client_quote'])]])['kind'] ?? 'rfq';
        $policy = FollowupPolicy::latestFor($kind);

        return view('followups.settings', ['kind' => $kind, 'policy' => $policy, 'defaults' => $policy?->snapshot ?? ManageFollowups::defaults($kind), 'history' => FollowupPolicy::where('kind', $kind)->latest('number')->get()]);
    }

    public function savePolicy(FollowupPolicyRequest $r, ManageFollowups $a): RedirectResponse
    {
        $a->policy($r->user(), $r->validated());

        return to_route('settings.followups', ['kind' => $r->validated('kind')])->with('status', 'New policy version approved. Existing activation snapshots and cumulative counts are preserved.');
    }

    public function show(Request $r, string $kind, int $approval, ManageFollowups $a): View
    {
        abort_unless(in_array($kind, ['rfq', 'client_quote'], true), 404);
        $source = $kind === 'rfq' ? RfqApproval::findOrFail($approval) : ClientQuotationApproval::findOrFail($approval);
        $inquiry = $kind === 'rfq' ? $source->revision->rfq->inquiry : $source->revision->quotation->inquiry;
        Gate::authorize('update', $inquiry);
        $target = $kind.':'.($kind === 'rfq' ? $source->revision->rfq_id : $source->revision->client_quotation_id);
        $plan = FollowupPlan::with('authorization', 'stages.envelope.dispatches', 'tasks')->where('target_key', $target)->first();
        $options = $r->validate(['mode' => ['nullable', Rule::in(['automatic', 'manual_review'])], 'attach' => ['nullable', 'boolean'], 'acknowledge_response' => ['nullable', 'boolean'], 'manual_sent_at' => ['nullable', 'date_format:Y-m-d\\TH:i:s']]);
        $mode = $options['mode'] ?? 'manual_review';
        $snapshot = null;
        $blockers = [];
        try {
            $snapshot = $a->preview($kind, $approval, $r->user(), $mode, $r->boolean('attach'), $r->boolean('acknowledge_response'), $r->input('manual_sent_at'));
        } catch (ValidationException $e) {
            $blockers = array_merge(...array_values($e->errors()));
        }

        return view('followups.show', compact('kind', 'approval', 'source', 'inquiry', 'plan', 'snapshot', 'blockers', 'mode'));
    }

    public function activate(Request $r, string $kind, int $approval, ManageFollowups $a): RedirectResponse
    {
        $d = $r->validate(['mode' => ['required', Rule::in(['automatic', 'manual_review'])], 'digest' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:2000'], 'attach' => ['nullable', 'boolean'], 'acknowledge_response' => ['nullable', 'boolean'], 'manual_sent_at' => ['nullable', 'date_format:Y-m-d\\TH:i:s'], 'confirm' => ['required', 'accepted']]);
        $a->activate($kind, $approval, $r->user(), $d);

        return to_route('followups.show', [$kind, $approval])->with('status', 'Exact bounded follow-up policy activated. The first stage waits until its approved date.');
    }

    public function control(Request $r, FollowupPlan $plan, ManageFollowups $a): RedirectResponse
    {
        Gate::authorize('update', $plan->inquiry);
        $d = $r->validate(['action' => ['required', Rule::in(['pause', 'cancel'])], 'reason' => ['required', 'string', 'max:2000']]);
        $a->halt($plan, $d['action'] === 'pause' ? 'paused' : 'cancelled', $d['reason'], $r->user());

        return back()->with('status', 'Follow-ups '.$d['action'].'d. Pending messages stopped before submission; a submitted message cannot be recalled.');
    }

    public function stage(Request $r, FollowupStage $stage): View
    {
        Gate::authorize('update', $stage->plan->inquiry);

        return view('followups.stage', compact('stage'));
    }

    public function approveStage(Request $r, FollowupStage $stage, ManageFollowups $a): RedirectResponse
    {
        $d = $r->validate(['digest' => ['required', 'size:64'], 'confirm' => ['required', 'accepted']]);
        $a->approveStage($stage, $r->user(), $d['digest']);

        return back()->with('status', 'This exact reminder approved and queued. Submission still rechecks replies and eligibility.');
    }

    public function index(Request $r): View
    {
        $d = $r->validate(['state' => ['nullable', Rule::in(['open', 'resolved'])], 'owner' => ['nullable', Rule::in(['mine', 'all'])]]);
        $tasks = AttentionTask::with('inquiry', 'owner', 'plan.authorization', 'message')->where('state', $d['state'] ?? 'open')->when(($d['owner'] ?? 'all') === 'mine', fn ($q) => $q->where('owner_id', $r->user()->id))->latest()->paginate(20)->withQueryString();

        return view('followups.index', ['tasks' => $tasks, 'staff' => User::where('is_active', true)->orderBy('name')->get(), 'plans' => FollowupPlan::with('inquiry', 'authorization')->latest()->limit(50)->get()]);
    }

    public function task(Request $r, AttentionTask $task): RedirectResponse
    {
        Gate::authorize('update', $task->inquiry);
        $d = $r->validate(['owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)], 'resolution' => ['nullable', 'string', 'max:2000'], 'action' => ['required', Rule::in(['assign', 'resolve'])]]);
        if ($d['action'] === 'resolve' && empty($d['resolution'])) {
            throw ValidationException::withMessages(['resolution' => 'Record what was reviewed or resolved.']);
        }
        DB::transaction(function () use ($task, $r, $d): void {
            $task = AttentionTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $task->update($d['action'] === 'assign' ? ['owner_id' => $d['owner_id'] ?? null] : ['state' => 'resolved', 'resolved_by' => $r->user()->id, 'resolved_at' => now(), 'resolution' => $d['resolution']]);
            Audit::record('Internal attention task '.$d['action'], $task, actor: $r->user());
        });

        return back()->with('status', 'Attention task updated. Resolving a task does not resume or send reminders.');
    }
}
