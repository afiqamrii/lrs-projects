<?php

namespace App\Actions;

use App\Models\Inquiry;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveInquiry
{
    public function handle(array $data, ?Inquiry $inquiry = null): Inquiry
    {
        return DB::transaction(function () use ($data, $inquiry): Inquiry {
            $creating = $inquiry === null;
            $record = $creating ? new Inquiry : InquiryWorkflow::locked($inquiry, (int) $data['lock_version']);
            if (! $creating && $record->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'Reopen this inquiry before editing its working shipment.']);
            }
            $before = Audit::snapshot($record);
            $oldSnapshot = $creating ? null : $record->snapshot();
            $oldHash = $creating ? null : $record->snapshotHash();
            $confirmed = $creating ? null : $record->versions()->where('number', $record->shipment_revision)->first();
            $record->fill(array_intersect_key($data, array_flip(['client_id', 'client_contact_id', 'title', 'owner_id', 'priority', 'internal_notes'])));
            $record->response_due_at = InquiryWorkflow::utc($data['response_due_at'] ?? null);
            $record->shipment = Shipment::normalize($data['shipment']);
            $record->unsetRelation('client');
            $record->unsetRelation('contact');
            $record->unsetRelation('owner');
            if ($creating) {
                $record->reference = InquiryWorkflow::reference();
                $record->received_at = InquiryWorkflow::utc($data['received_at']);
                $record->source_channel = $data['source_channel'];
                $record->original_source_text = $data['original_source_text'] ?? null;
                $record->shipment_revision = 1;
                $record->lock_version = 0;
                $record->status = 'draft';
            }
            $material = ! $creating && ($oldSnapshot !== $record->snapshot() || ($confirmed && ! hash_equals($confirmed->snapshot_hash, $record->snapshotHash())));
            if ($material) {
                if ($confirmed) {
                    $record->shipment_revision++;
                }
                if ($record->status !== 'on_hold') {
                    $record->status = 'draft';
                    $record->status_reason = null;
                }
                foreach ($record->clarifications()->whereIn('status', ['draft', 'approved'])->get() as $clarification) {
                    $old = Audit::snapshot($clarification);
                    $clarification->update(['status' => 'invalidated', 'lock_version' => $clarification->lock_version + 1]);
                    Audit::record('Clarification invalidated by material change', $clarification, $old);
                }
            }
            if (! $creating) {
                $record->lock_version++;
            }
            $record->save();
            $details = $material ? ['shipment_hash' => ['before' => $oldHash, 'after' => $record->snapshotHash()]] : [];
            Audit::record($creating ? 'Inquiry created' : ($material ? 'Working shipment revised' : 'Inquiry details updated'), $record, $before, null, null, $details);

            return $record;
        });
    }
}
