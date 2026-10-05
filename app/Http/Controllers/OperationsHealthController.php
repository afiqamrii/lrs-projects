<?php

namespace App\Http\Controllers;

use App\Models\AiBudgetDay;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\CompanySetting;
use App\Models\DocumentRun;
use App\Models\FollowupPlan;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Support\OutboundControl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationsHealthController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-company');
        $settings = CompanySetting::current();

        return view('operations.health', [
            'settings' => $settings,
            'queues' => DB::table('jobs')->selectRaw('queue, COUNT(*) AS backlog, MIN(created_at) AS oldest, COUNT(*) FILTER (WHERE reserved_at IS NOT NULL) AS reserved')->groupBy('queue')->orderBy('queue')->get(),
            'failedCount' => DB::table('failed_jobs')->count(),
            'failed' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->get(['uuid', 'queue', 'failed_at']),
            'mailboxes' => MailboxConnection::with('folders:id,mailbox_connection_id,name,enabled,kind,last_sync_at,failure_count,cycle_count')->orderBy('id')->get(),
            'dispatches' => MailDispatch::with('envelope.inquiry:id,reference', 'envelope.mailbox:id,provider')->where(fn ($query) => $query->whereIn('status', ['uncertain', 'submitting', 'failed'])->orWhere(fn ($accepted) => $accepted->where('status', 'accepted')->where('reconcile_attempts', '>=', 10)))->latest('id')->limit(25)->get(),
            'uncertainCount' => MailDispatch::whereIn('status', ['uncertain', 'submitting'])->count(),
            'exhaustedCount' => MailDispatch::where('reconcile_attempts', '>=', 10)->whereIn('status', ['accepted', 'uncertain', 'submitting'])->count(),
            'heldCount' => MailDispatch::whereIn('status', ['queued', 'preparing', 'ready', 'failed'])->where('outbound_epoch', '<', $settings->outbound_epoch)->count(),
            'plans' => FollowupPlan::with('inquiry:id,reference')->whereIn('state', ['held', 'paused', 'exhausted'])->latest('id')->limit(25)->get(),
            'extractions' => DocumentRun::with('inquiry:id,reference')->whereIn('state', ['failed', 'partial', 'unavailable', 'manual_review'])->latest('id')->limit(25)->get(),
            'ai' => AiSetting::current(),
            'budget' => AiBudgetDay::where('day', now()->utc()->format('Y-m-d'))->first(),
            'uncertainAi' => AiRun::where('is_demo', false)->where('cost_uncertain', true)->count(),
        ]);
    }

    public function control(Request $request, OutboundControl $control): RedirectResponse
    {
        Gate::authorize('manage-company');
        $d = $request->validate(['action' => ['required', Rule::in(['pause', 'resume'])], 'epoch' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:12', 'max:2000'], 'confirm' => ['required', 'accepted']]);
        $control->change($request->user(), $d['action'] === 'pause', (int) $d['epoch'], $d['reason']);

        return to_route('operations.health')->with('status', $d['action'] === 'pause' ? 'Outgoing mail paused. Incoming capture continues. A request already at submission cannot be recalled.' : 'Outgoing control resumed. Earlier queued work stays blocked until explicit current recovery; reminders need a new exact activation.');
    }
}
