<?php

namespace App\Actions;

use App\Http\Requests\InquiryRequest;
use App\Models\AiRun;
use App\Models\Inquiry;
use App\Models\ProposalReview;
use App\Models\User;
use App\Support\AiSources;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\Shipment;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewProposals
{
    public function preview(Inquiry $inquiry, AiRun $run, User $staff, array $decisions, int $expected): array
    {
        Gate::forUser($staff)->authorize('update', $inquiry);
        abort_unless($run->inquiry_id === $inquiry->id && $run->purpose === 'shipment_proposals', 404);
        $record = $inquiry->fresh();
        if ($record->lock_version !== $expected) {
            Processing::fail('This inquiry changed during review. Compare the current shipment and preview your selected decisions again.');
        }
        if ($run->state !== 'needs_review' || ! hash_equals($run->shipment_hash, $record->snapshotHash()) || ! AiSources::current($record, $run->sources)) {
            Processing::fail('This proposal run is stale or unavailable. Compare its evidence with current values; request a fresh snapshot or edit the working inquiry manually.');
        }
        Validator::make(['decisions' => $decisions], [
            'decisions' => ['required', 'array', 'max:60'], 'decisions.*' => ['array:action,value,reason,acknowledge'],
            'decisions.*.action' => ['required', Rule::in(['accept', 'correct', 'reject', 'unresolved'])],
            'decisions.*.reason' => ['nullable', 'string', 'max:2000'], 'decisions.*.acknowledge' => ['nullable', 'boolean'],
        ])->validate();
        $candidates = collect($run->proposals)->keyBy('id');
        $shipment = $record->shipment;
        $reviewed = [];
        $appliedFields = [];
        foreach ($decisions as $id => $decision) {
            $candidate = $candidates->get((string) $id);
            if (! $candidate) {
                Processing::fail('A review decision references an unknown proposal.');
            }
            $action = $decision['action'];
            $field = $candidate['field'];
            $reason = trim((string) ($decision['reason'] ?? ''));
            $value = $candidate['value'];
            if ($action === 'accept' && (! $candidate['supported'] || $candidate['ambiguous'] || $value === null)) {
                throw ValidationException::withMessages(['decisions.'.$id.'.action' => 'This value cannot be accepted as source-supported. Correct it with a documented manual explanation, reject it or leave it unresolved.']);
            }
            if (in_array($action, ['accept', 'correct'], true)) {
                if (isset($appliedFields[$field])) {
                    Processing::fail('Select only one accepted/corrected proposal for each field. Competing candidates remain visible for comparison.');
                }
                $appliedFields[$field] = true;
                if (($candidate['conflict'] || $action === 'correct') && mb_strlen($reason) < 8) {
                    throw ValidationException::withMessages(['decisions.'.$id.'.reason' => 'Explain the correction or conflict decision (at least 8 characters).']);
                }
                $current = $shipment[$field] ?? null;
                $populated = $current !== null && $current !== '' && $current !== [] && $current !== 'unknown';
                if (($candidate['conflict'] || $populated) && ! ($decision['acknowledge'] ?? false)) {
                    throw ValidationException::withMessages(['decisions.'.$id.'.acknowledge' => 'Explicitly acknowledge replacing this populated field or resolving its conflict.']);
                }
                if ($action === 'correct') {
                    $value = $decision['value'] ?? (in_array($field, ['services', 'special_flags', 'packages', 'containers'], true) ? [] : null);
                    if (is_string($value) && trim($value) === '') {
                        $value = null;
                    }
                }
                $shipment[$field] = $value;
            }
            $reviewed[(string) $id] = ['action' => $action, 'field' => $field, 'value' => in_array($action, ['accept', 'correct'], true) ? $value : null, 'reason' => $reason ?: null, 'acknowledge' => (bool) ($decision['acknowledge'] ?? false), 'evidence_status' => $action === 'correct' ? 'documented_manual_correction' : $candidate['label']];
        }
        foreach (['packages' => 'LCL', 'containers' => 'FCL'] as $field => $mode) {
            if (isset($appliedFields[$field]) && ($shipment['mode'] ?? 'unknown') !== $mode && ! empty($shipment[$field])) {
                Processing::fail('Accept the matching '.$mode.' mode along with these rows, or enter them in the working shipment editor.');
            }
        }
        $payload = [
            'client_id' => $record->client_id, 'client_contact_id' => $record->client_contact_id,
            'title' => $record->title, 'owner_id' => $record->owner_id, 'priority' => $record->priority,
            'response_due_at' => InquiryWorkflow::local($record->response_due_at) ?: null, 'internal_notes' => $record->internal_notes,
            'shipment' => $shipment, 'lock_version' => $record->lock_version,
        ];
        $request = new InquiryRequest;
        $request->replace($payload);
        $route = new Route(['PATCH'], 'inquiries/{inquiry}', fn (): null => null);
        $route->bind(Request::create('/inquiries/'.$record->id, 'PATCH'));
        $route->setParameter('inquiry', $record);
        $request->setRouteResolver(fn (): Route => $route);
        $request->setUserResolver(fn (): User => $staff);
        $validator = Validator::make($payload, $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }
        $validator->validate();
        $normalized = Shipment::normalize($shipment);
        $changes = [];
        foreach ($normalized as $field => $after) {
            if (Processing::hash(['value' => $record->shipment[$field] ?? null]) !== Processing::hash(['value' => $after])) {
                $changes[$field] = ['before' => $record->shipment[$field] ?? null, 'after' => $after];
            }
        }

        return ['decisions' => $reviewed, 'payload' => $payload, 'changes' => $changes, 'lock_version' => $record->lock_version, 'source_hash' => Processing::hash($run->sources), 'shipment_hash' => $record->snapshotHash(), 'confirmed' => $record->versions()->where('number', $record->shipment_revision)->exists()];
    }

    public function apply(Inquiry $inquiry, AiRun $run, User $staff, array $preview, string $key): ProposalReview
    {
        Gate::forUser($staff)->authorize('update', $inquiry);
        abort_unless($run->inquiry_id === $inquiry->id && $run->purpose === 'shipment_proposals', 404);

        return DB::transaction(function () use ($inquiry, $run, $staff, $preview, $key): ProposalReview {
            $existing = ProposalReview::where('action_key', $key)->first();
            if ($existing) {
                abort_unless($existing->ai_run_id === $run->id, 404);

                return $existing;
            }
            $record = InquiryWorkflow::locked($inquiry, $preview['lock_version']);
            $lockedRun = AiRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $record->documents()->orderBy('id')->lockForUpdate()->get();
            $decisions = [];
            foreach ($preview['decisions'] as $id => $decision) {
                $decisions[$id] = array_intersect_key($decision, array_flip(['action', 'value', 'reason', 'acknowledge']));
            }
            $current = $this->preview($record, $lockedRun, $staff, $decisions, $preview['lock_version']);
            if (Processing::hash($current) !== Processing::hash($preview)) {
                Processing::fail('The proposed change preview is no longer current. Compare and preview again before applying.');
            }
            $before = $record->shipment;
            $result = $current['changes'] ? app(SaveInquiry::class)->handle($current['payload'], $record) : $record;
            $review = ProposalReview::create(['ai_run_id' => $run->id, 'reviewer_id' => $staff->id, 'action_key' => $key, 'decision_hash' => Processing::hash($current['decisions']), 'decisions' => $current['decisions'], 'before_values' => $before, 'after_values' => $result->shipment, 'resulting_revision' => $result->shipment_revision, 'reviewed_at' => now()]);
            $resolved = collect($current['decisions'])->reject(fn (array $decision): bool => $decision['action'] === 'unresolved')->count();
            $lockedRun->update(['review_outcome' => $resolved === count($lockedRun->proposals) ? 'reviewed' : 'partly_reviewed']);
            Audit::record('AI proposals reviewed by staff', $result, Audit::snapshot($result), details: ['proposal_review' => ['before' => null, 'after' => ['run_id' => $run->id, 'review_id' => $review->id, 'revision' => $result->shipment_revision]]]);

            return $review;
        });
    }
}
