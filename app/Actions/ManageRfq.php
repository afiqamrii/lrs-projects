<?php

namespace App\Actions;

use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\Rfq;
use App\Models\RfqApproval;
use App\Models\RfqDispatch;
use App\Models\RfqRevision;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\RfqEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageRfq
{
    public function locked(Rfq $rfq, User $staff, int $expected): Rfq
    {
        Gate::forUser($staff)->authorize('update', $rfq);
        CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
        Inquiry::whereKey($rfq->inquiry_id)->lockForUpdate()->firstOrFail();
        $record = Rfq::whereKey($rfq->id)->lockForUpdate()->firstOrFail();
        if ($record->current_number !== $expected) {
            throw ValidationException::withMessages(['expected_revision' => 'Another agent saved revision '.$record->current_number.' after your preview. Your approval was rejected. Open the current request and compare its revision history.']);
        }
        Vendor::whereKey($record->vendor_id)->lockForUpdate()->firstOrFail();
        $record->vendor->contacts()->orderBy('id')->lockForUpdate()->get();
        $record->inquiry->documents()->orderBy('id')->lockForUpdate()->get();

        return $record;
    }

    public function save(Rfq $rfq, User $staff, array $data): RfqRevision
    {
        return DB::transaction(function () use ($rfq, $staff, $data): RfqRevision {
            $record = $this->locked($rfq, $staff, (int) $data['expected_revision']);
            if (RfqEligibility::readiness($record->inquiry) || $record->round->version->snapshot_hash !== $record->inquiry->snapshotHash()) {
                throw ValidationException::withMessages(['eligibility' => 'Reconfirm the current shipment and open its sourcing round before preparing a revised request.']);
            }
            $previous = $record->current();
            $p = $previous->payload;
            foreach (['subject', 'opening', 'closing', 'vendor_notes', 'alternative_notes', 'disclosure_notes', 'limitation_reason', 'shared_email_reason', 'deadline_reason'] as $key) {
                $p[$key] = isset($data[$key]) ? RfqContent::clean($data[$key]) : null;
            }
            $p['to'] = $this->recipient($record, (int) $data['to_contact_id']);
            $p['cc'] = [];
            foreach ($data['cc_contact_ids'] ?? [] as $id) {
                $p['cc'][] = $this->recipient($record, (int) $id);
            }
            if (in_array($p['to']['email'], array_column($p['cc'], 'email'), true)) {
                throw ValidationException::withMessages(['cc_contact_ids' => 'The To contact cannot also be selected in CC.']);
            }
            usort($p['cc'], fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $p['response_due_at'] = InquiryWorkflow::utc($data['response_due_at'] ?? null)?->toIso8601String();
            $p['currency'] = $data['currency'];
            $p['deadline_ack_client_due_at'] = ! empty($p['deadline_reason']) ? $record->inquiry->response_due_at?->toIso8601String() : null;
            foreach (['disclose_identity', 'disclose_addresses', 'attachments_reviewed'] as $key) {
                $p[$key] = (bool) ($data[$key] ?? false);
            }
            $p['manifest'] = RfqContent::manifest($record, $data['document_ids'] ?? []);
            if ($data['refresh_company'] ?? false) {
                $p['company'] = RfqContent::company();
            }
            $p['origin'] = $data['_origin'] ?? 'manual';
            if (Processing::hash($p) === Processing::hash($previous->payload) && ! in_array($previous->status, ['cancelled', 'superseded'], true)) {
                return $previous;
            }
            $number = $record->current_number + 1;
            $revision = $record->revisions()->create(['number' => $number, 'payload' => $p, 'created_by' => $staff->id, 'change_reason' => $data['change_reason'] ?? null]);
            $previous->update(['status' => 'superseded']);
            $record->update(['current_number' => $number]);
            Audit::record('RFQ draft revision saved', $record, actor: $staff, vendorId: $record->vendor_id, details: ['revision' => ['before' => $previous->number, 'after' => $number]]);

            return $revision;
        });
    }

    private function recipient(Rfq $rfq, int $id): array
    {
        $contact = $rfq->vendor->contacts()->whereKey($id)->where('is_active', true)->first();
        if (! $contact || ! filter_var($contact->email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $contact->email)) {
            throw ValidationException::withMessages(['to_contact_id' => 'Choose usable active To/CC contacts belonging to this vendor.']);
        }

        return ['id' => $contact->id, 'name' => $contact->name, 'email' => mb_strtolower(trim($contact->email))];
    }

    public function approve(Rfq $rfq, User $staff, int $expected, string $digest): RfqApproval
    {
        Gate::forUser($staff)->authorize('approve', $rfq);

        return DB::transaction(function () use ($rfq, $staff, $expected, $digest): RfqApproval {
            $record = $this->locked($rfq, $staff, $expected);
            $revision = $record->current();
            if ($existing = $revision->approval) {
                if (! hash_equals($existing->digest, $digest)) {
                    throw ValidationException::withMessages(['digest' => 'Review the exact approved evidence before repeating this action.']);
                }
                RfqEligibility::assert($revision, true);

                return $existing;
            }
            $snapshot = RfqContent::snapshot($revision);
            if (! hash_equals(Processing::hash($snapshot), $digest)) {
                throw ValidationException::withMessages(['digest' => 'This preview differs from the saved revision. Review the exact current content again.']);
            }
            RfqEligibility::assert($revision);
            if ($revision->approval) {
                return $revision->approval;
            }
            $approval = RfqApproval::create(['rfq_revision_id' => $revision->id, 'snapshot' => $snapshot, 'digest' => $digest, 'approved_by' => $staff->id, 'approver_name' => $staff->name, 'approved_at' => now()]);
            $revision->update(['status' => 'approved']);
            Audit::record('Exact RFQ revision approved', $record, actor: $staff, vendorId: $record->vendor_id, details: ['approval' => ['before' => null, 'after' => ['revision' => $expected, 'digest' => $digest, 'approval_id' => $approval->id]]]);

            return $approval;
        });
    }

    public function state(Rfq $rfq, User $staff, int $expected, string $target, string $reason): void
    {
        DB::transaction(function () use ($rfq, $staff, $expected, $target, $reason): void {
            $record = $this->locked($rfq, $staff, $expected);
            $revision = $record->current();
            if ($target === 'cancelled') {
                if ($revision->approval?->dispatch) {
                    throw ValidationException::withMessages(['target' => 'This exact request was already manually recorded as sent. Cancellation cannot undo it. Prepare a revised request if necessary.']);
                }
            } elseif (in_array($revision->status, ['approved', 'superseded', 'cancelled'], true)) {
                throw ValidationException::withMessages(['target' => 'Save a new draft revision before changing its review state.']);
            }
            if ($target === 'needs_approval') {
                RfqEligibility::assert($revision);
            }
            $before = $revision->status;
            $revision->update(['status' => $target]);
            Audit::record('RFQ review state changed', $record, actor: $staff, vendorId: $record->vendor_id, details: ['review_state' => ['before' => $before, 'after' => ['state' => $target, 'reason' => $reason]]]);
        });
    }

    public function manual(Rfq $rfq, User $staff, array $data): RfqDispatch
    {
        return DB::transaction(function () use ($rfq, $staff, $data): RfqDispatch {
            $record = $this->locked($rfq, $staff, (int) $data['expected_revision']);
            $revision = $record->current();
            RfqEligibility::assert($revision, true);
            $approval = $revision->approval;
            if (! hash_equals($approval->digest, $data['digest'])) {
                throw ValidationException::withMessages(['digest' => 'Confirm the exact approved version shown before recording a send.']);
            }
            if (MailDispatch::where('source_key', 'rfq:'.$approval->id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['manual_send' => 'This approval has an Outlook dispatch. Inspect its evidence; a deliberate resend needs a new approved revision.']);
            }
            $existing = $approval->dispatch;
            if ($existing) {
                if ($existing->action_key !== $data['action_key']) {
                    throw ValidationException::withMessages(['manual_send' => 'This approved version already has a manual dispatch. Prepare and approve a new revision for a deliberate resend.']);
                }

                return $existing;
            }
            if (mb_strtolower(trim($data['recipient'])) !== $approval->snapshot['to']['email']) {
                throw ValidationException::withMessages(['recipient' => 'Declare the exact approved primary recipient. All approved CC recipients must also have been used.']);
            }
            $sent = InquiryWorkflow::utc($data['sent_at']);
            if ($sent->isFuture() || $sent->lessThan($approval->approved_at->startOfMinute())) {
                throw ValidationException::withMessages(['sent_at' => 'Use the actual past sending time, after this revision was approved.']);
            }
            $dispatch = RfqDispatch::create(['rfq_approval_id' => $approval->id, 'action_key' => $data['action_key'], 'recorded_by' => $staff->id, 'actor_name' => $staff->name, 'sent_at' => $sent, 'recorded_at' => now(), 'channel' => $data['channel'], 'recipients' => ['to' => $approval->snapshot['to'], 'cc' => $approval->snapshot['cc']], 'evidence' => $data['evidence'] ?? null]);
            Audit::record('RFQ manually recorded as sent', $record, actor: $staff, vendorId: $record->vendor_id, details: ['manual_dispatch' => ['before' => null, 'after' => ['revision' => $revision->number, 'dispatch_id' => $dispatch->id, 'channel' => $data['channel'], 'declared_sent_at' => $sent->toIso8601String()]]]);

            return $dispatch;
        });
    }
}
