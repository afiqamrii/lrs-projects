<?php

namespace App\Actions;

use App\Http\Requests\ClientDecisionRequest;
use App\Http\Requests\HandoffEventRequest;
use App\Http\Requests\HandoffPolicyRequest;
use App\Http\Requests\HandoffRequest;
use App\Http\Requests\VendorConfirmationRequest;
use App\Models\AttentionTask;
use App\Models\BookingHandoff;
use App\Models\ClientDecision;
use App\Models\ClientQuotationRevision;
use App\Models\CompanySetting;
use App\Models\FollowupPlan;
use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\HandoffPolicy;
use App\Models\HandoffRevision;
use App\Models\Inquiry;
use App\Models\MailMessage;
use App\Models\User;
use App\Models\VendorConfirmation;
use App\Models\VendorReconfirmation;
use App\Support\Audit;
use App\Support\HandoffPdf;
use App\Support\LifecycleEligibility;
use App\Support\Processing;
use App\Support\QuotationEligibility;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ManageLifecycle
{
    public function lock(Inquiry $i, User $staff): Inquiry
    {
        LifecycleEligibility::staff($staff);
        Gate::forUser($staff)->authorize('update', $i);
        CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
        $i = Inquiry::whereKey($i->id)->lockForUpdate()->firstOrFail();
        $i->client?->newQuery()->whereKey($i->client_id)->lockForUpdate()->first();
        $i->client?->contacts()->orderBy('id')->lockForUpdate()->get();
        $i->documents()->orderBy('id')->lockForUpdate()->get();
        $q = LifecycleEligibility::quote($i);
        if ($q) {
            $q->selection->newQuery()->whereKey($q->offer_selection_id)->lockForUpdate()->first();
            $vendor = $q->selection->revision->offer->vendor;
            $vendor->newQuery()->whereKey($vendor->id)->lockForUpdate()->first();
            $vendor->contacts()->orderBy('id')->lockForUpdate()->get();
        }

        return $i;
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, CompanySetting::current()->timezone)->utc();
    }

    private function actualTime(CarbonImmutable $time): void
    {
        if ($time->isFuture()) {
            Processing::fail('Evidence must describe an actual event, with a time no later than now.');
        }
    }

    private function duplicate(string $model, array $data, User $staff): ?object
    {
        $old = $model::where('action_key', $data['action_key'])->first();
        if ($old && ($old->request_digest !== Processing::hash([$data, $staff->id]))) {
            Processing::fail('This action identity already recorded different evidence. Reload and use a new action.');
        }

        return $old;
    }

    public function stop(Inquiry $i, string $reason, ?User $staff = null, ?int $quote = null, ?int $message = null): void
    {
        $plans = FollowupPlan::where('inquiry_id', $i->id)->whereIn('state', ['active', 'held', 'paused']);
        if ($quote) {
            $plans->where('kind', 'client_quote')->whereHas('authorization.clientQuotationApproval', fn ($a) => $a->where('client_quotation_revision_id', $quote));
        }
        foreach ($plans->orderBy('id')->get() as $plan) {
            app(ManageFollowups::class)->halt($plan, 'stopped', $reason, $staff, $message);
        }
    }

    public function decision(ClientQuotationRevision $q, User $staff, array $input): ClientDecision
    {
        $data = Validator::make($input, ClientDecisionRequest::inputRules())->validate();

        return DB::transaction(function () use ($q, $staff, $data) {
            $i = $this->lock($q->quotation->inquiry, $staff);
            $q = $q->fresh();
            if ($old = $this->duplicate(ClientDecision::class, $data, $staff)) {
                if ($old->client_quotation_revision_id !== $q->id) {
                    Processing::fail('Action belongs to a different quotation.');
                }

                return $old;
            }
            $latest = LifecycleEligibility::decision($q);
            if ((int) $data['expected_decision'] !== ($latest?->id ?? 0) || ($latest && (int) ($data['corrects_id'] ?? 0) !== $latest->id) || (! $latest && ! empty($data['corrects_id']))) {
                Processing::fail('The decision changed. Reload; corrections must append to the latest exact decision and retain its evidence.');
            }
            $time = $this->time($data['decided_at']);
            $this->actualTime($time);
            $contact = $i->client?->contacts()->whereKey($data['contact_id'] ?? 0)->where('is_active', true)->first();
            $reasons = array_merge(QuotationEligibility::reasons($q), LifecycleEligibility::replacementReasons($q));
            if (! $q->approval) {
                $reasons[] = 'This exact quotation is not approved.';
            }
            if (! $contact || ! in_array($contact->id, array_column(array_merge($q->payload['to'] ? [$q->payload['to']] : [], $q->payload['cc']), 'id'), true)) {
                $reasons[] = 'Choose a known authorized recipient of this exact client quotation.';
            }
            if (empty($data['identity_confirmed']) || empty($data['scope_confirmed'])) {
                $reasons[] = 'Identity and unconditional acceptance of the exact scope, total and terms need human confirmation.';
            }
            if (! empty($data['conditional'])) {
                $reasons[] = 'Conditional acceptance requires resolution and renewed client agreement.';
            }
            if ($q->expires_at && $time->gt($q->expires_at)) {
                $reasons[] = 'The stated decision occurred after quotation expiry.';
            }
            if ($q->approval && $time->lt($q->approval->approved_at)) {
                $reasons[] = 'The stated decision predates exact quotation approval.';
            }
            $m = null;
            if ($data['channel'] === 'email') {
                $m = MailMessage::find($data['mail_message_id'] ?? 0);
                if ($m && $m->inquiry_id !== $i->id) {
                    Processing::fail('Review and associate this original email with the correct inquiry before recording an outcome.');
                }
                if (! $m || $m->direction !== 'incoming' || $m->inquiry_id !== $i->id || $m->client_quotation_revision_id !== $q->id || $m->match_state !== 'matched' || ! $m->response_reviewed_at || $m->sender_email !== $contact?->email || $m->is_demo !== $i->is_demo || in_array($m->classification, ['out_of_office', 'bounce', 'automated', 'noise'], true)) {
                    $reasons[] = 'Email acceptance needs a human-reviewed source matched to the exact quotation and authorized client contact.';
                }
                if ($m?->received_at && $time->lt($m->received_at)) {
                    $reasons[] = 'Decision time precedes the received source.';
                }
            } elseif (! empty($data['mail_message_id'])) {
                Processing::fail('Choose Email for an email source.');
            }
            if (empty($data['communicated_confirmed'])) {
                $reasons[] = 'Confirm this exact approved quotation was communicated to the client.';
            }
            $documents = LifecycleEligibility::documents($i, array_merge($data['document_ids'] ?? [], empty($data['po_document_id']) ? [] : [(int) $data['po_document_id']]));
            $outcome = $data['outcome'] === 'accepted' && $reasons ? 'review_required' : $data['outcome'];
            $snapshot = ['schema' => 'lrs-client-decision-1', 'quotation_id' => $q->id, 'quotation_revision' => $q->number, 'quotation_digest' => $q->digest, 'quotation_reference' => $q->quotation->reference,
                'customer' => $q->approval?->snapshot['customer'] ?? $q->payload['customer'] ?? null, 'pdf_checksum' => $q->pdf_checksum,
                'contact' => $contact?->only(['id', 'name', 'email', 'phone']), 'source' => $m?->only(['id', 'mailbox_connection_id', 'provider_id', 'internet_id', 'sender_email', 'received_at', 'source_hash', 'lock_version']),
                'reference' => $data['reference'] ?? null, 'po_document_id' => $data['po_document_id'] ?? null, 'documents' => $documents, 'notes' => $data['notes'], 'decline_reason' => $data['decline_reason'] ?? null, 'change_scope' => $data['change_scope'] ?? 'unknown',
                'requested_outcome' => $data['outcome'], 'outcome' => $outcome, 'blocking_reasons' => $data['outcome'] === 'accepted' ? $reasons : [],
                'identity_confirmed' => ! empty($data['identity_confirmed']), 'scope_confirmed' => ! empty($data['scope_confirmed']), 'conditional' => ! empty($data['conditional']),
                'communicated_confirmed' => ! empty($data['communicated_confirmed']), 'channel' => $data['channel'], 'decided_at' => $time->toIso8601String(), 'reviewer' => $staff->only(['id', 'name']), 'reviewed_at' => now()->toIso8601String()];
            $d = ClientDecision::create(['inquiry_id' => $i->id, 'client_quotation_revision_id' => $q->id, 'number' => ($latest?->number ?? 0) + 1, 'action_key' => $data['action_key'], 'request_digest' => Processing::hash([$data, $staff->id]),
                'corrects_id' => $latest?->id, 'mail_message_id' => $m?->id, 'client_contact_id' => $contact?->id, 'requested_outcome' => $data['outcome'], 'outcome' => $outcome, 'channel' => $data['channel'],
                'snapshot' => $snapshot, 'digest' => Processing::hash($snapshot), 'decided_at' => $time, 'reviewed_by' => $staff->id, 'created_at' => now()]);
            $this->stop($i, 'Client decision/question recorded; review retained evidence. A new explicit activation is required.', $staff, $q->id, $m?->id);
            if ($outcome === 'accepted') {
                $r = VendorReconfirmation::create(['inquiry_id' => $i->id, 'client_decision_id' => $d->id, 'offer_selection_id' => $q->offer_selection_id, 'shipment_version_id' => $q->selection->snapshot['shipment_version_id']]);
                $this->task($i, 'vendor_reconfirmation_id', $r->id, 'vendor_reconfirmation', 'Reconfirm vendor rate and capacity', 'Review the accepted cost and shipment. Draft and explicitly approve any vendor message.');
            } elseif (in_array($outcome, ['revision_requested', 'review_required', 'question'], true)) {
                $this->task($i, 'client_quotation_revision_id', $q->id, 'client_'.$outcome, 'Review client '.str_replace('_', ' ', $outcome), $outcome === 'revision_requested' ? 'Create a new draft. Shipment/cost changes need renewed confirmation, reviewed offer and selection; wording uses the existing quotation revision path.' : 'Resolve the identity, condition or question and append a reviewed decision.', $m?->id);
            }
            Audit::record('Client decision reviewed', $d, actor: $staff, details: ['decision' => ['before' => $latest?->outcome, 'after' => ['outcome' => $outcome, 'requested' => $data['outcome'], 'quote' => $q->id, 'event_at' => $time->toIso8601String(), 'corrects' => $latest?->id]]]);

            return $d;
        }, 3);
    }

    public function confirmation(VendorReconfirmation $request, User $staff, array $input): VendorConfirmation
    {
        $data = Validator::make($input, VendorConfirmationRequest::inputRules())->validate();

        return DB::transaction(function () use ($request, $staff, $data) {
            $i = $this->lock($request->inquiry, $staff);
            $r = VendorReconfirmation::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($old = $this->duplicate(VendorConfirmation::class, $data, $staff)) {
                if ($old->vendor_reconfirmation_id !== $r->id) {
                    Processing::fail('Action belongs to a different reconfirmation.');
                }

                return $old;
            }
            if ((int) $data['expected_revision'] !== $r->current_number) {
                Processing::fail('Vendor confirmation changed. Reload and retain the latest evidence.');
            }
            $time = $this->time($data['confirmed_at']);
            $this->actualTime($time);
            $expiry = empty($data['expires_at']) ? null : $this->time($data['expires_at']);
            if ($expiry && $expiry->lte($time)) {
                Processing::fail('Vendor expiry must follow the actual confirmation time.');
            }
            $vendor = $r->selection->revision->offer->vendor;
            $contact = $vendor->contacts()->whereKey($data['contact_id'] ?? 0)->where('is_active', true)->first();
            $m = null;
            $issues = [];
            $changes = [];
            if (! $contact || empty($data['identity_confirmed'])) {
                $issues[] = 'Verify an active authorized vendor contact.';
            }
            if ($time->lt($r->decision->created_at)) {
                $issues[] = 'Evidence predates this acceptance reconfirmation request.';
            }
            if ($data['channel'] === 'email') {
                $m = MailMessage::find($data['mail_message_id'] ?? 0);
                if ($m && $m->inquiry_id !== $i->id) {
                    Processing::fail('Review and associate this original email with the correct inquiry before recording an outcome.');
                }
                if (! $m || ! $m->response_reviewed_at || $m->direction !== 'incoming' || $m->inquiry_id !== $i->id || $m->match_state !== 'matched' || $m->is_demo !== $i->is_demo || $m->sender_email !== $contact?->email || $m->operationalMessage?->vendor_reconfirmation_id !== $r->id || in_array($m->classification, ['out_of_office', 'bounce', 'automated', 'noise'], true)) {
                    $issues[] = 'Review the actual vendor email against the exact reconfirmation message and contact.';
                }
                if ($m?->received_at && $time->lt($m->received_at)) {
                    $issues[] = 'Confirmation time precedes the received vendor source.';
                }
            } elseif (! empty($data['mail_message_id'])) {
                Processing::fail('Choose Email for an email source.');
            }
            $native = $r->selection->revision;
            if (isset($data['rate_total']) && $data['rate_total'] !== '' && (! BigDecimal::of($data['rate_total'])->isEqualTo(BigDecimal::of($native->complete_total)) || strtoupper($data['currency'] ?? '') !== $native->currency)) {
                $changes[] = 'Quoted rate or currency changed; reviewed vendor offer, selection and renewed client quotation/decision are required.';
            }
            $s = $r->selection->snapshot['shipment']['shipment'];
            foreach (['available_date' => 'cargo_ready_date', 'arrival_date' => 'arrival_date'] as $field => $original) {
                if (! empty($data[$field]) && ! empty($s[$original]) && $data[$field] !== $s[$original]) {
                    $changes[] = 'Vendor '.str_replace('_', ' ', $field).' differs from the confirmed shipment.';
                }
            }
            if ($data['status'] === 'confirmed') {
                foreach (['rate_agreed', 'scope_agreed', 'capacity_confirmed', 'dates_agreed'] as $field) {
                    if (empty($data[$field])) {
                        $issues[] = 'Explicit '.str_replace('_', ' ', $field).' evidence is missing.';
                    }
                }
                if (! isset($data['rate_total']) || $data['rate_total'] === '' || empty($data['currency']) || empty($data['available_date'])) {
                    $issues[] = 'Record the actual rate/currency and available cargo date.';
                }
            }
            if ($data['status'] === 'confirmed' && ! empty($s['arrival_date']) && empty($data['arrival_date'])) {
                $issues[] = 'Record the actual arrival date agreed in the confirmed shipment.';
            }
            $status = $data['status'];
            if ($status === 'confirmed') {
                $status = $changes ? 'changed' : (! empty($data['conditions']) ? 'conditional' : ($issues ? 'pending' : 'confirmed'));
            }
            $snapshot = ['schema' => 'lrs-vendor-confirmation-1', 'request_id' => $r->id, 'decision_id' => $r->client_decision_id, 'selection_id' => $r->offer_selection_id, 'shipment_version_id' => $r->shipment_version_id,
                'contact' => $contact?->only(['id', 'name', 'email', 'phone']), 'source' => $m?->only(['id', 'mailbox_connection_id', 'provider_id', 'internet_id', 'sender_email', 'operational_message_id', 'source_hash', 'lock_version']),
                'data' => $data, 'status' => $status, 'differences' => $changes, 'review_gaps' => $issues, 'documents' => LifecycleEligibility::documents($i, $data['document_ids'] ?? []), 'reviewer' => $staff->only(['id', 'name']), 'reviewed_at' => now()->toIso8601String()];
            $v = VendorConfirmation::create(['inquiry_id' => $i->id, 'vendor_reconfirmation_id' => $r->id, 'number' => $r->current_number + 1, 'action_key' => $data['action_key'], 'request_digest' => Processing::hash([$data, $staff->id]),
                'mail_message_id' => $m?->id, 'contact_id' => $contact?->id, 'status' => $status, 'snapshot' => $snapshot, 'digest' => Processing::hash($snapshot), 'confirmed_at' => $time, 'expires_at' => $expiry, 'reviewed_by' => $staff->id, 'created_at' => now()]);
            $r->update(['current_number' => $v->number]);
            if ($status !== 'confirmed') {
                $this->task($i, 'vendor_reconfirmation_id', $r->id, 'vendor_'.$status, 'Vendor '.ucfirst($status), $status === 'changed' ? 'Review the changed offer and shipment, select the new cost basis and create a replacement client quotation. Renew client acceptance.' : 'Resolve vendor conditions or availability before handoff.', $m?->id);
                $this->stop($i, 'Vendor confirmation changed or remains unresolved.', $staff);
            }
            Audit::record('Vendor reconfirmation reviewed', $v, actor: $staff, details: ['confirmation' => ['before' => null, 'after' => ['status' => $status, 'differences' => $changes, 'event_at' => $time->toIso8601String()]]]);

            return $v;
        }, 3);
    }

    public function policy(User $staff, array $input): HandoffPolicy
    {
        LifecycleEligibility::staff($staff);
        abort_unless($staff->role === 'admin', 403);
        $d = Validator::make($input, HandoffPolicyRequest::inputRules())->validate();

        return DB::transaction(function () use ($staff, $d) {
            CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $old = HandoffPolicy::current();
            if ((int) $d['expected_revision'] !== ($old?->number ?? 0)) {
                Processing::fail('Company requirements changed. Reload and review the current policy.');
            }
            $s = ['schema' => 'lrs-handoff-policy-1', 'freshness_hours' => empty($d['freshness_hours']) ? null : (int) $d['freshness_hours'], 'require_po' => ! empty($d['require_po']), 'require_deposit' => ! empty($d['require_deposit']),
                'required_documents' => array_values($d['required_documents'] ?? []), 'operational_exceptions' => array_values($d['operational_exceptions'] ?? [])];
            $p = HandoffPolicy::create(['number' => ($old?->number ?? 0) + 1, 'snapshot' => $s, 'digest' => Processing::hash($s), 'approved_by' => $staff->id, 'reason' => $d['reason'], 'created_at' => now()]);
            Audit::record('Handoff requirements approved', $p, actor: $staff, details: ['policy' => ['before' => $old?->id, 'after' => $p->id]]);

            return $p;
        }, 3);
    }

    public function saveHandoff(Inquiry $i, User $staff, array $input): HandoffRevision
    {
        LifecycleEligibility::staff($staff);
        $d = Validator::make($input, HandoffRequest::inputRules())->validate();
        $e = $d;
        unset($e['expected_revision'],$e['reason']);
        if (! empty($e['deposit_at'])) {
            $depositTime = $this->time($e['deposit_at']);
            $this->actualTime($depositTime);
            $e['deposit_at'] = $depositTime->toIso8601String();
        }
        $e['document_ids'] = array_values(array_unique(array_map('intval', array_merge($e['document_ids'] ?? [], array_filter($e['document_map'] ?? [])))));
        $e['exceptions'] = array_filter($e['exceptions'] ?? [], fn ($x) => trim((string) $x) !== '');
        if ($e['exceptions']) {
            abort_unless($staff->role === 'admin', 403);
            $allowed = HandoffPolicy::current()?->snapshot['operational_exceptions'] ?? [];
            if (array_diff(array_keys($e['exceptions']), $allowed)) {
                Processing::fail('This operational exception is not permitted by the approved company policy.');
            }
            $e['exception_authority'] = $staff->id;
            $e['exception_approved_at'] = now()->toIso8601String();
        }
        $ready = LifecycleEligibility::readiness($i, $e);
        $s = ['schema' => 'lrs-internal-handoff-1', 'inquiry_id' => $i->id, 'reference' => $i->reference, 'revision' => (int) $d['expected_revision'] + 1,
            'evidence' => $e, 'checklist' => $ready['items'], 'dependencies' => $ready['dependencies'], 'customer' => $ready['quote']?->approval?->snapshot['customer'],
            'selected_cost' => $ready['request']?->selection->snapshot, 'pricing' => $ready['quote']?->pricing, 'vendor_confirmation' => $ready['confirmation']?->snapshot,
            'reason' => $d['reason'], 'prepared_by' => $staff->only(['id', 'name']), 'prepared_at' => now()->toIso8601String(), 'internal_only' => true];
        $file = HandoffPdf::store($s, true);
        try {
            return DB::transaction(function () use ($i, $staff, $d, $e, $ready, $s, $file) {
                $i = $this->lock($i, $staff);
                $now = LifecycleEligibility::readiness($i, $e);
                if ($ready['dependency_digest'] !== $now['dependency_digest']) {
                    Processing::fail('Readiness changed while preparing the private summary. Reload.');
                }
                $h = BookingHandoff::firstOrCreate(['inquiry_id' => $i->id]);
                $h = BookingHandoff::whereKey($h->id)->lockForUpdate()->firstOrFail();
                if ((int) $d['expected_revision'] !== $h->current_number) {
                    Processing::fail('A newer handoff revision exists. Reload.');
                }
                $r = HandoffRevision::create(['booking_handoff_id' => $h->id, 'number' => $h->current_number + 1, 'client_decision_id' => $now['decision']?->id, 'client_quotation_revision_id' => $now['quote']?->id,
                    'offer_selection_id' => $now['request']?->offer_selection_id, 'shipment_version_id' => $now['request']?->shipment_version_id, 'vendor_confirmation_id' => $now['confirmation']?->id, 'handoff_policy_id' => $now['policy']?->id,
                    'state' => $now['ready'] ? 'ready' : 'incomplete', 'snapshot' => $s, 'dependency_digest' => $now['dependency_digest'], 'digest' => Processing::hash($s)] + $file + ['created_by' => $staff->id, 'created_at' => now()]);
                $h->update(['current_number' => $r->number]);
                Audit::record('Internal handoff revision prepared', $r, actor: $staff, details: ['handoff' => ['before' => null, 'after' => ['number' => $r->number, 'ready' => $now['ready'], 'inquiry_id' => $i->id]]]);

                return $r;
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('inquiry_documents')->delete($file['pdf_path']);
            throw $error;
        }
    }

    public function approveHandoff(HandoffRevision $r, User $staff, string $digest): HandoffApproval
    {
        LifecycleEligibility::staff($staff);
        if ($issues = LifecycleEligibility::revisionReasons($r)) {
            Processing::fail(implode(' ', $issues));
        }
        if (! hash_equals($r->digest, $digest)) {
            Processing::fail('Review the exact current handoff snapshot.');
        }
        $s = ['schema' => 'lrs-handoff-approval-1', 'revision_digest' => $r->digest, 'handoff' => $r->snapshot, 'approved_by' => $staff->only(['id', 'name']), 'approved_at' => now()->toIso8601String()];
        $file = HandoffPdf::store($s['handoff'] + ['approval' => ['name' => $staff->name, 'at' => $s['approved_at']]], false);
        try {
            $a = DB::transaction(function () use ($r, $staff, $s, $file) {
                $this->lock($r->handoff->inquiry, $staff);
                User::whereKey($r->snapshot['evidence']['operations_owner_id'] ?? 0)->lockForUpdate()->first();
                BookingHandoff::whereKey($r->booking_handoff_id)->lockForUpdate()->firstOrFail();
                if ($issues = LifecycleEligibility::revisionReasons($r->fresh())) {
                    Processing::fail(implode(' ', $issues));
                }
                if ($old = $r->approval()->first()) {
                    return $old;
                }
                $a = HandoffApproval::create(['handoff_revision_id' => $r->id, 'snapshot' => $s, 'digest' => Processing::hash($s)] + $file + ['approved_by' => $staff->id, 'approved_at' => $s['approved_at']]);
                Audit::record('Handoff approved', $a, actor: $staff, details: ['approval' => ['before' => null, 'after' => ['inquiry_id' => $r->handoff->inquiry_id, 'revision' => $r->id, 'digest' => $a->digest]]]);

                return $a;
            }, 3);
            if ($a->pdf_path !== $file['pdf_path']) {
                Storage::disk('inquiry_documents')->delete($file['pdf_path']);
            }

            return $a;
        } catch (\Throwable $e) {
            Storage::disk('inquiry_documents')->delete($file['pdf_path']);
            throw $e;
        }
    }

    public function event(HandoffApproval $a, User $staff, array $input): HandoffEvent
    {
        $d = Validator::make($input, HandoffEventRequest::inputRules())->validate();

        return DB::transaction(function () use ($a, $staff, $d) {
            $i = $this->lock($a->revision->handoff->inquiry, $staff);
            if ($old = $this->duplicate(HandoffEvent::class, $d, $staff)) {
                if ($old->handoff_approval_id !== $a->id) {
                    Processing::fail('This action belongs to a different handoff.');
                }

                return $old;
            }
            LifecycleEligibility::approved($a);
            $time = $this->time($d['occurred_at']);
            $this->actualTime($time);
            if ($time->lt($a->approved_at)) {
                Processing::fail('This event must occur after exact handoff approval.');
            }
            if ($d['kind'] !== 'handed_to_operations') {
                $handed = HandoffEvent::where('handoff_approval_id', $a->id)->where('kind', 'handed_to_operations')->first();
                if (! $handed || $time->lt($handed->occurred_at)) {
                    Processing::fail('Record the actual handoff to operations before booking request or confirmation evidence.');
                }
            }
            $prior = HandoffEvent::where('handoff_approval_id', $a->id)->where('kind', $d['kind'])->latest('id')->first();
            if ($prior && ($d['kind'] === 'handed_to_operations' || (int) ($d['corrects_id'] ?? 0) !== $prior->id)) {
                Processing::fail('This event is already recorded. Booking corrections must reference the latest evidence.');
            }
            if (! $prior && ! empty($d['corrects_id'])) {
                Processing::fail('Correction belongs to another event.');
            }
            $s = ['data' => $d, 'documents' => LifecycleEligibility::documents($i, $d['document_ids'] ?? []), 'recorder' => $staff->only(['id', 'name']), 'handoff_digest' => $a->digest, 'recorded_at' => now()->toIso8601String()];
            if ($d['kind'] === 'booking_confirmed') {
                if (! HandoffEvent::where('handoff_approval_id', $a->id)->where('kind', 'handed_to_operations')->exists()) {
                    Processing::fail('Record the actual handoff to operations before booking evidence.');
                }
                $r = $a->revision;
                $vendor = $r->confirmation->request->selection->revision->offer->vendor;
                $contact = $vendor->contacts()->whereKey($d['contact_id'] ?? 0)->where('is_active', true)->first();
                if (! $contact || empty($d['vendor_reference']) || empty($d['pickup_date']) || empty($d['scope_confirmed'])) {
                    Processing::fail('Actual booking evidence needs an authorized vendor contact, booking reference, agreed pickup date and explicit scope confirmation.');
                }
                $expected = $r->confirmation->snapshot['data'];
                if ($d['pickup_date'] !== ($expected['available_date'] ?? null) || (! empty($expected['arrival_date']) && ($d['arrival_date'] ?? null) !== $expected['arrival_date'])) {
                    Processing::fail('Booking dates differ from the agreed confirmation. Review the change and obtain a newly approved handoff.');
                }
                if (! empty($d['mail_message_id'])) {
                    $m = MailMessage::findOrFail($d['mail_message_id']);
                    if (! $m->response_reviewed_at || $m->direction !== 'incoming' || $m->is_demo !== $i->is_demo || $m->match_state !== 'matched' || $m->inquiry_id !== $i->id || $m->sender_email !== $contact->email || in_array($m->classification, ['out_of_office', 'bounce', 'automated', 'noise'], true) || ($m->operationalMessage?->handoff_revision_id !== $r->id && $m->operationalMessage?->vendor_reconfirmation_id !== $r->confirmation->vendor_reconfirmation_id) || ($m->received_at && $time->lt($m->received_at))) {
                        Processing::fail('Review booking email against the exact vendor message and sender.');
                    }
                    $s['source'] = $m->only(['id', 'internet_id', 'provider_id', 'mailbox_connection_id', 'sender_email', 'source_hash', 'lock_version', 'received_at']);
                }
                $s['contact'] = $contact->only(['id', 'name', 'email', 'phone']);
            }
            $event = HandoffEvent::create(['inquiry_id' => $i->id, 'handoff_approval_id' => $a->id, 'corrects_id' => $prior?->id, 'action_key' => $d['action_key'], 'request_digest' => Processing::hash([$d, $staff->id]), 'kind' => $d['kind'], 'snapshot' => $s, 'digest' => Processing::hash($s), 'occurred_at' => $time, 'recorded_by' => $staff->id, 'created_at' => now()]);
            Audit::record(str_replace('_', ' ', $d['kind']), $event, actor: $staff, details: ['event' => ['before' => $prior?->id, 'after' => ['handoff' => $a->id, 'at' => $time->toIso8601String(), 'reference' => $d['vendor_reference'] ?? null]]]);

            return $event;
        }, 3);
    }

    public function task(Inquiry $i, string $target, int $id, string $kind, string $title, string $next, ?int $message = null): AttentionTask
    {
        return AttentionTask::firstOrCreate(['dedup_key' => 'lifecycle:'.$target.':'.$id.':'.$kind.':'.($message ?? 0)], ['inquiry_id' => $i->id, $target => $id, 'mail_message_id' => $message, 'owner_id' => $i->owner_id, 'kind' => $kind, 'title' => $title, 'next_action' => $next]);
    }

    public function incoming(MailMessage $m, bool $recover = false): void
    {
        if ($m->direction !== 'incoming' || $m->match_state !== 'matched' || ! $m->inquiry_id || in_array($m->classification, ['out_of_office', 'bounce', 'automated', 'noise'], true)) {
            return;
        }
        if ($m->client_quotation_revision_id) {
            $task = $this->task($m->inquiry, 'client_quotation_revision_id', $m->client_quotation_revision_id, 'client_response', 'Review client response', 'Compare the exact quote, terms and total with this original response. Confirm a commercial decision separately.', $m->id);
            if (! $recover || $task->wasRecentlyCreated) {
                $this->stop($m->inquiry, 'Client response requires staff review; final acceptance is separate.', quote: $m->client_quotation_revision_id, message: $m->id);
            }
        } elseif ($op = $m->operationalMessage) {
            $target = $op->kind === 'reconfirmation' ? 'vendor_reconfirmation_id' : 'handoff_revision_id';
            $id = $op->$target;
            $this->task($m->inquiry, $target, $id, 'vendor_response', 'Review vendor operational response', 'Assess the actual vendor rate, scope, dates and capacity or booking reference; thread matching alone does not confirm anything.', $m->id);
        }
    }
}
