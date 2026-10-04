<?php

namespace App\Support;

use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    private const FIELDS = ['company_name', 'display_name', 'type', 'services', 'coverage', 'minimum_notes', 'communication_channel', 'internal_notes', 'is_active', 'name', 'email', 'role', 'phone', 'is_primary', 'timezone', 'currency', 'reference_identifier', 'address', 'reference', 'client_id', 'client_contact_id', 'title', 'owner_id', 'priority', 'status', 'received_at', 'response_due_at', 'source_channel', 'shipment_revision', 'status_reason', 'recipient_email', 'approved_by', 'approved_at', 'communicated_at', 'kind', 'channel', 'recipient', 'occurred_at', 'original_name', 'mime', 'size', 'checksum', 'classification', 'is_archived', 'provenance', 'scan_status', 'public_intake_enabled', 'public_service_intro', 'public_contact_email', 'public_contact_phone', 'public_contact_address', 'public_privacy_version', 'public_intake_owner_id', 'receipt_mail_enabled', 'rfq_reply_name', 'rfq_reply_email', 'version', 'prepared_from_id'];

    public static function snapshot(Model $record): array
    {
        return array_intersect_key($record->attributesToArray(), array_flip(self::FIELDS));
    }

    public static function record(string $action, Model $record, array $before = [], ?int $vendorId = null, ?User $actor = null, array $details = [], ?string $systemActor = null): void
    {
        $changes = [];
        foreach (self::snapshot($record) as $field => $value) {
            if (! array_key_exists($field, $before) || $before[$field] !== $value) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
            }
        }
        $changes = array_merge($changes, $details);
        if ($changes === [] && ! str_contains($action, 'password')) {
            return;
        }
        $actor = $systemActor ? null : ($actor ?? auth()->user());
        AuditEntry::create(['actor_id' => $actor?->id, 'actor_name' => $systemActor ?? $actor?->name ?? 'Secure setup', 'action' => $action, 'record_type' => class_basename($record), 'record_id' => $record->id, 'vendor_id' => $vendorId, 'client_id' => $record instanceof Client ? $record->id : ($record->client_id ?? null), 'inquiry_id' => $record instanceof Inquiry ? $record->id : ($record->inquiry_id ?? null), 'record_label' => $record->reference ?? $record->company_name ?? $record->name ?? $record->original_name ?? $record->display_name ?? class_basename($record).' #'.$record->id, 'changes' => $changes, 'created_at' => now()]);
    }
}
