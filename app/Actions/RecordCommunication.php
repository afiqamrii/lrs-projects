<?php

namespace App\Actions;

use App\Models\Inquiry;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use Illuminate\Support\Facades\DB;

class RecordCommunication
{
    public function handle(Inquiry $inquiry, array $data): void
    {
        DB::transaction(function () use ($inquiry, $data): void {
            $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $entry = $record->communications()->create(['author_id' => auth()->id(), 'author_name' => auth()->user()->name, 'kind' => $data['kind'], 'channel' => $data['channel'], 'recipient' => $data['recipient'] ?? null, 'occurred_at' => InquiryWorkflow::utc($data['occurred_at']), 'notes' => $data['notes'], 'created_at' => now()]);
            $entry->documents()->sync($data['document_ids'] ?? []);
            Audit::record($data['kind'] === 'client_response' ? 'Client answer manually recorded' : 'Communication manually recorded', $entry, [], null, null, ['document_count' => ['before' => null, 'after' => count($data['document_ids'] ?? [])]]);
            if ($data['kind'] === 'client_response' && $record->status === 'needs_client_information') {
                $before = Audit::snapshot($record);
                $record->update(['status' => 'needs_review', 'lock_version' => $record->lock_version + 1]);
                Audit::record('Client answer returned inquiry to review', $record, $before);
            }
        });
    }
}
