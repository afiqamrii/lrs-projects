<?php

namespace App\Actions;

use App\Models\Clarification;
use App\Models\ClientContact;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageClarification
{
    public function prepare(Inquiry $inquiry, int $expected): Clarification
    {
        return DB::transaction(function () use ($inquiry, $expected): Clarification {
            $record = InquiryWorkflow::locked($inquiry, $expected);
            if (in_array($record->status, ['closed', 'ready_for_sourcing'], true)) {
                throw ValidationException::withMessages(['status' => 'Return the inquiry to review before preparing a clarification.']);
            }
            if (! $record->contact?->is_active) {
                throw ValidationException::withMessages(['client_contact_id' => 'Select an active client contact before preparing a clarification.']);
            }
            $clarification = $record->clarifications()->create(['shipment_revision' => $record->shipment_revision, 'shipment_hash' => $record->snapshotHash(), 'client_contact_id' => $record->client_contact_id, 'recipient_email' => $record->contact->email, 'body' => InquiryWorkflow::clarificationBody($record), 'status' => 'draft', 'lock_version' => 0]);
            if ($record->status === 'draft') {
                $before = Audit::snapshot($record);
                $record->update(['status' => 'needs_review', 'lock_version' => $record->lock_version + 1]);
                Audit::record('Inquiry submitted for review', $record, $before);
            }
            Audit::record('Clarification draft prepared', $clarification, [], null, null, ['message_hash' => ['before' => null, 'after' => hash('sha256', $clarification->body)]]);

            return $clarification;
        });
    }

    public function edit(Inquiry $inquiry, Clarification $clarification, array $data): void
    {
        DB::transaction(function () use ($inquiry, $clarification, $data): void {
            $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $item = $record->clarifications()->whereKey($clarification->id)->lockForUpdate()->firstOrFail();
            $this->check($item, (int) $data['lock_version']);
            if ($item->status !== 'draft' || ! $item->currentFor($record)) {
                throw ValidationException::withMessages(['body' => 'This draft is no longer editable. Prepare a fresh clarification for the current shipment.']);
            }
            $contact = ClientContact::whereKey($data['client_contact_id'])->where('client_id', $record->client_id)->where('is_active', true)->firstOrFail();
            $before = Audit::snapshot($item);
            $oldHash = hash('sha256', $item->body);
            $item->update(['body' => $data['body'], 'client_contact_id' => $contact->id, 'recipient_email' => $contact->email, 'lock_version' => $item->lock_version + 1]);
            Audit::record('Clarification draft edited', $item, $before, null, null, ['message_hash' => ['before' => $oldHash, 'after' => hash('sha256', $item->body)]]);
        });
    }

    public function approve(Inquiry $inquiry, Clarification $clarification, int $expected): void
    {
        DB::transaction(function () use ($inquiry, $clarification, $expected): void {
            $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $item = $record->clarifications()->whereKey($clarification->id)->lockForUpdate()->firstOrFail();
            $this->check($item, $expected);
            if ($record->status === 'closed' || $item->status !== 'draft' || ! $item->currentFor($record)) {
                throw ValidationException::withMessages(['body' => 'The recipient or shipment changed. Prepare a current clarification before approval.']);
            }
            $before = Audit::snapshot($item);
            $item->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'lock_version' => $item->lock_version + 1]);
            Audit::record('Exact manual clarification approved', $item, $before, null, null, ['message_hash' => ['before' => null, 'after' => hash('sha256', $item->body)]]);
        });
    }

    public function communicated(Inquiry $inquiry, Clarification $clarification, array $data): void
    {
        DB::transaction(function () use ($inquiry, $clarification, $data): void {
            $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $item = $record->clarifications()->whereKey($clarification->id)->lockForUpdate()->firstOrFail();
            $this->check($item, (int) $data['lock_version']);
            if ($record->status === 'closed' || $item->status !== 'approved' || ! $item->currentFor($record) || $data['recipient'] !== $item->recipient_email) {
                throw ValidationException::withMessages(['recipient' => 'Record only the exact approved, current clarification and recipient. A material change requires fresh approval.']);
            }
            if (MailDispatch::where('source_key', 'clarification:'.$item->id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['recipient' => 'This clarification has an Outlook dispatch. Inspect its status instead of recording another send.']);
            }
            $before = Audit::snapshot($item);
            $at = InquiryWorkflow::utc($data['occurred_at']);
            $item->update(['status' => 'communicated', 'communicated_at' => $at, 'lock_version' => $item->lock_version + 1]);
            $communication = $record->communications()->create(['author_id' => auth()->id(), 'author_name' => auth()->user()->name, 'clarification_id' => $item->id, 'kind' => 'clarification', 'channel' => $data['channel'], 'recipient' => $data['recipient'], 'occurred_at' => $at, 'notes' => $item->body, 'created_at' => now()]);
            if ($record->status === 'needs_review') {
                $old = Audit::snapshot($record);
                $record->update(['status' => 'needs_client_information', 'lock_version' => $record->lock_version + 1]);
                Audit::record('Inquiry awaiting client information', $record, $old);
            }
            Audit::record('Clarification manually communicated', $item, $before);
            Audit::record('Manual communication recorded', $communication);
        });
    }

    private function check(Clarification $item, int $expected): void
    {
        if ($item->lock_version !== $expected) {
            throw ValidationException::withMessages(['lock_version' => 'This clarification changed. Reload it before editing, approving, or recording communication.']);
        }
    }
}
