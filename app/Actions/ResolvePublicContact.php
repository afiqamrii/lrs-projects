<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\Inquiry;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use Illuminate\Support\Facades\DB;

class ResolvePublicContact
{
    public function handle(Inquiry $inquiry, array $data): Inquiry
    {
        return DB::transaction(function () use ($inquiry, $data): Inquiry {
            $record = InquiryWorkflow::locked($inquiry, (int) $data['lock_version']);
            $contact = $data['contact'];
            if ($data['resolution'] === 'defer') {
                $client = $record->client;
                $selected = $record->contact;
            } elseif ($data['resolution'] === 'new_client') {
                $client = app(SaveClient::class)->handle(['company_name' => $contact['company']]);
            } else {
                $client = Client::whereKey($data['client_id'])->where('is_active', true)->firstOrFail();
            }
            if ($data['resolution'] === 'existing') {
                $selected = $client->contacts()->whereKey($data['client_contact_id'])->where('is_active', true)->firstOrFail();
            } elseif ($data['resolution'] !== 'defer') {
                $selected = app(SaveClientContact::class)->handle($client, ['name' => $contact['name'], 'email' => $contact['email'], 'phone' => $contact['phone'] ?? null, 'is_active' => true, 'is_primary' => $data['resolution'] === 'new_client']);
            }
            $prior = $record->public_contact;
            if (($prior['email'] ?? null) !== $contact['email']) {
                $record->mailboxVerifications()->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
            }
            $record->public_contact = $contact;
            $record->save();
            $saved = app(SaveInquiry::class)->handle(['lock_version' => $record->lock_version, 'client_id' => $client?->id, 'client_contact_id' => $selected?->id, 'title' => $record->title, 'owner_id' => $record->owner_id, 'priority' => $record->priority, 'internal_notes' => $record->internal_notes, 'response_due_at' => InquiryWorkflow::local($record->response_due_at), 'shipment' => $record->shipment], $record);
            Audit::record($data['resolution'] === 'defer' ? 'Public working contact corrected; assessment deferred' : 'Public client identity resolved by staff', $saved, Audit::snapshot($saved), details: ['resolution' => ['before' => null, 'after' => $data['resolution']], 'contact_hash' => ['before' => hash('sha256', json_encode($prior)), 'after' => hash('sha256', json_encode($contact))], 'selected_contact' => ['before' => null, 'after' => $selected?->id]]);

            return $saved;
        });
    }
}
