<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\Inquiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InquiryWorkflow
{
    public const TRANSITIONS = [
        'draft' => ['needs_review', 'on_hold', 'closed'],
        'needs_review' => ['draft', 'needs_client_information', 'ready_for_sourcing', 'on_hold', 'closed'],
        'needs_client_information' => ['draft', 'needs_review', 'on_hold', 'closed'],
        'ready_for_sourcing' => ['needs_review', 'on_hold', 'closed'],
        'on_hold' => ['resume', 'closed'],
        'closed' => ['reopen'],
    ];

    public static function reference(): string
    {
        $sequence = DB::selectOne("SELECT nextval('inquiry_reference_seq') AS number")->number;

        return 'LRS-'.now(CompanySetting::current()->timezone)->year.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public static function utc(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, CompanySetting::current()->timezone)->utc() : null;
    }

    public static function local(mixed $value): string
    {
        return $value ? $value->setTimezone(CompanySetting::current()->timezone)->format('Y-m-d\TH:i') : '';
    }

    public static function locked(Inquiry $inquiry, int $expected): Inquiry
    {
        $record = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
        if ($record->lock_version !== $expected) {
            throw ValidationException::withMessages(['lock_version' => 'This inquiry changed while you were editing. Reload the record, compare your changes, and try again. Your submitted values are preserved below.']);
        }

        return $record;
    }

    public static function gaps(Inquiry $inquiry): array
    {
        $s = $inquiry->shipment;
        $gaps = [];
        if (! $inquiry->owner?->is_active) {
            $gaps['owner_id'] = 'Assign an active responsible staff member.';
        }
        if (! $inquiry->client?->is_active) {
            $gaps['client_id'] = $inquiry->client_id ? 'Reactivate this client before confirming the inquiry.' : 'Resolve the submitted client identity before confirming the inquiry.';
        }
        if (! $inquiry->contact?->is_active || $inquiry->contact?->client_id !== $inquiry->client_id) {
            $gaps['client_contact_id'] = 'Select an active contact belonging to this client.';
        }
        if (! $inquiry->response_due_at) {
            $gaps['response_due_at'] = 'Set a response deadline.';
        }
        foreach (['cargo_description' => 'Describe the cargo.', 'origin_country' => 'Specify the origin country.', 'origin_location' => 'Specify the origin port or location.', 'destination_country' => 'Specify the destination country.', 'destination_location' => 'Specify the destination port or location.', 'cargo_ready_date' => 'Set the cargo-ready date.'] as $key => $text) {
            if (empty($s[$key])) {
                $gaps['shipment.'.$key] = $text;
            }
        }
        if (! in_array($s['mode'] ?? 'unknown', ['LCL', 'FCL'], true)) {
            $gaps['shipment.mode'] = 'Confirm LCL or FCL.';
        }
        if (($s['scope'] ?? 'unknown') === 'unknown') {
            $gaps['shipment.scope'] = 'Confirm the requested logistics service scope.';
        }
        if ((in_array($s['scope'] ?? '', ['door_to_port', 'door_to_door'], true) || in_array('pickup', $s['services'] ?? [], true)) && empty($s['pickup_address'])) {
            $gaps['shipment.pickup_address'] = 'Provide the requested pickup address.';
        }
        if ((in_array($s['scope'] ?? '', ['port_to_door', 'door_to_door'], true) || in_array('delivery', $s['services'] ?? [], true)) && empty($s['delivery_address'])) {
            $gaps['shipment.delivery_address'] = 'Provide the requested delivery address.';
        }
        if (! empty($s['incoterm']) && empty($s['named_place'])) {
            $gaps['shipment.named_place'] = 'Record the client-stated Incoterm named place, or clarify it.';
        }
        if (! empty($s['special_flags']) || ! empty($s['special_notes']) || collect($s['containers'] ?? [])->contains(fn ($row) => in_array($row['type'] ?? '', ['20RF', '40RF', 'Other'], true))) {
            $gaps['shipment.special_flags'] = 'Special cargo or handling requires manual specialist review. Place this inquiry on hold; do not confirm ordinary general-cargo readiness.';
        }
        if (($s['mode'] ?? 'unknown') === 'LCL') {
            $rows = $s['packages'] ?? [];
            if ($rows === []) {
                $gaps['shipment.packages'] = 'Add at least one package group with quantity and gross weight.';
            }
            $declared = ! empty($s['declared_volume']) && ! empty($s['declared_volume_source']);
            if (! empty($s['declared_volume']) && empty($s['declared_volume_source'])) {
                $gaps['shipment.declared_volume_source'] = 'Record the source of the declared volume.';
            }
            foreach ($rows as $index => $row) {
                foreach (['packaging_type' => 'packaging type', 'quantity' => 'quantity', 'gross_weight' => 'gross weight', 'weight_unit' => 'weight unit'] as $key => $label) {
                    if (empty($row[$key])) {
                        $gaps["shipment.packages.$index.$key"] = 'Package group '.($index + 1).': provide '.$label.'.';
                    }
                }
                if (! $declared) {
                    foreach (['length', 'width', 'height', 'dimension_unit'] as $key) {
                        if (empty($row[$key])) {
                            $gaps["shipment.packages.$index.$key"] = 'Package group '.($index + 1).': provide dimensions and units, or declared total volume with its source.';
                        }
                    }
                }
            }
        }
        if (($s['mode'] ?? 'unknown') === 'FCL') {
            if (empty($s['containers'])) {
                $gaps['shipment.containers'] = 'Add a container row.';
            }
            foreach ($s['containers'] ?? [] as $index => $row) {
                foreach (['type' => 'container type', 'quantity' => 'container quantity', 'gross_weight' => 'cargo gross weight (row total)', 'weight_unit' => 'cargo weight unit'] as $key => $label) {
                    if (empty($row[$key])) {
                        $gaps["shipment.containers.$index.$key"] = 'Container row '.($index + 1).': provide '.$label.'.';
                    }
                }
            }
        }

        return $gaps;
    }

    public static function clarificationBody(Inquiry $inquiry): string
    {
        $questions = [];
        foreach ($inquiry->gaps() as $key => $text) {
            if (str_starts_with($key, 'shipment.') && $key !== 'shipment.special_flags') {
                $questions[] = '• '.$text;
            }
        }
        if ($questions === []) {
            $questions[] = '• Please confirm the shipment details and any outstanding requirements.';
        }

        return strtr(config('inquiries.clarification_template'), ['{name}' => $inquiry->contact?->name ?? 'team', '{reference}' => $inquiry->reference, '{questions}' => implode("\n", array_unique($questions)), '{company}' => CompanySetting::current()->display_name]);
    }
}
