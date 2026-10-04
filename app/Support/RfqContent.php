<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\Rfq;
use App\Models\RfqRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RfqContent
{
    public static function clean(string $text): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($text)));
    }

    public static function company(): array
    {
        $company = CompanySetting::current();

        return ['name' => $company->display_name, 'reply_name' => $company->rfq_reply_name, 'reply_email' => $company->rfq_reply_email, 'signature' => $company->rfq_signature, 'timezone' => $company->timezone];
    }

    public static function defaults(Rfq $rfq): array
    {
        $s = $rfq->round->version->snapshot['shipment'];

        return ['to' => null, 'cc' => [], 'subject' => $rfq->reference.' · '.$s['mode'].' · '.$s['origin_location'].' to '.$s['destination_location'],
            'opening' => 'Please provide your quotation and available service options for the confirmed requirements below.',
            'closing' => 'Please identify any assumptions or unavailable services clearly. Thank you for reviewing this request.',
            'vendor_notes' => null, 'alternative_notes' => null, 'response_due_at' => null, 'currency' => CompanySetting::current()->currency,
            'company' => self::company(), 'manifest' => [], 'attachments_reviewed' => false, 'disclose_identity' => false, 'disclose_addresses' => false,
            'disclosure_notes' => null, 'limitation_reason' => null, 'shared_email_reason' => null, 'deadline_reason' => null, 'origin' => 'template'];
    }

    public static function manifest(Rfq $rfq, array $ids): array
    {
        $manifest = [];
        foreach ($ids as $id) {
            $doc = $rfq->inquiry->documents()->whereKey($id)->first();
            if (! $doc || $doc->is_archived || $doc->classification === 'freight_quote') {
                throw ValidationException::withMessages(['document_ids' => 'Select available files from this inquiry. Customer freight quotations cannot be disclosed directly; upload a separately prepared copy.']);
            }
            $path = Storage::disk('inquiry_documents')->path($doc->storage_path);
            if (! is_file($path) || hash_file('sha256', $path) !== $doc->checksum) {
                throw ValidationException::withMessages(['document_ids' => 'A selected file is missing or its checksum changed. Ask Admin to restore it or select a reviewed replacement.']);
            }
            $manifest[] = ['document_id' => $doc->id, 'version' => (int) $doc->version, 'checksum' => $doc->checksum, 'name' => $doc->original_name, 'mime' => $doc->mime, 'size' => $doc->size, 'classification' => $doc->classification, 'scan_status' => $doc->scan_status, 'prepared_from_id' => $doc->prepared_from_id];
        }
        usort($manifest, fn (array $a, array $b): int => $a['document_id'] <=> $b['document_id']);

        return $manifest;
    }

    public static function facts(Rfq $rfq, array $payload): string
    {
        $s = $rfq->round->version->snapshot['shipment'];
        $lines = ['CONFIRMED SHIPMENT · version '.$rfq->round->version->number,
            'Route: '.$s['origin_location'].', '.$s['origin_country'].' → '.$s['destination_location'].', '.$s['destination_country'],
            'Mode: '.$s['mode'], 'Cargo: '.$s['cargo_description'], 'Scope: '.Shipment::SCOPES[$s['scope']],
            'Cargo-ready date: '.$s['cargo_ready_date']];
        if ($s['arrival_date']) {
            $lines[] = 'Requested arrival target: '.$s['arrival_date'].' (requested target; no delivery commitment)';
        }
        if ($s['timing_flexibility']) {
            $lines[] = 'Timing flexibility: '.$s['timing_flexibility'];
        }
        foreach ($s[$s['mode'] === 'FCL' ? 'containers' : 'packages'] as $i => $row) {
            if ($s['mode'] === 'FCL') {
                $lines[] = 'Container group '.($i + 1).': '.$row['quantity'].' × '.$row['type'].'; cargo gross weight '.$row['gross_weight'].' '.$row['weight_unit'].' (group total)';
            } else {
                $line = 'Package group '.($i + 1).': '.$row['quantity'].' '.$row['packaging_type'].'; gross weight '.$row['gross_weight'].' '.$row['weight_unit'].' (group total)';
                if ($row['length'] && $row['width'] && $row['height']) {
                    $line .= '; each package '.$row['length'].' × '.$row['width'].' × '.$row['height'].' '.$row['dimension_unit'];
                }
                $lines[] = $line;
            }
        }
        $totals = Shipment::totals($s);
        if ($totals['weight'] !== null) {
            $lines[] = 'Total cargo gross weight: '.$totals['weight'].' kg';
        }
        if ($s['mode'] === 'LCL') {
            if ($totals['volume'] !== null) {
                $lines[] = 'Calculated total package volume: '.$totals['volume'].' m³';
            }
            if ($s['declared_volume']) {
                $lines[] = 'Declared total volume: '.$s['declared_volume'].' m³; source: '.$s['declared_volume_source'];
            }
        }
        $lines[] = 'Additional services: '.(implode(', ', array_map(fn (string $key): string => Shipment::SERVICES[$key], $s['services'])) ?: 'None requested');
        if ($s['incoterm']) {
            $lines[] = 'Client-stated Incoterm: '.$s['incoterm'].' · '.$s['named_place'];
        }
        if ($s['special_notes'] || $s['special_flags']) {
            $lines[] = 'Special requirements: '.implode(', ', $s['special_flags']).' '.$s['special_notes'];
        }
        foreach (['pickup_address' => 'Pickup', 'delivery_address' => 'Delivery'] as $key => $label) {
            if ($s[$key]) {
                $lines[] = $label.' address: '.($payload['disclose_addresses'] ? $s[$key] : 'Withheld — explicit disclosure review required for this service');
            }
        }
        if ($payload['disclose_identity']) {
            $lines[] = 'Client identity (explicit disclosure): '.$rfq->inquiry->client->company_name;
        }

        return implode("\n", $lines);
    }

    public static function snapshot(RfqRevision $revision): array
    {
        $rfq = $revision->rfq;
        $p = $revision->payload;
        $name = $p['to']['name'] ?? 'quotation team';
        $deadline = $p['response_due_at'] ? CarbonImmutable::parse($p['response_due_at'])->setTimezone($p['company']['timezone'])->format('d M Y, H:i T') : 'Not selected';
        $checklist = "QUOTATION REQUIREMENTS\nPlease quote in ".$p['currency']." and state:\n• Freight, origin, destination and requested extra-service charges separately.\n• Charging unit/basis, minimum charges and quantity assumptions.\n• Inclusions, exclusions, taxes and surcharge treatment.\n• Quote validity and rate-expiry conditions.\n• Estimated transit, availability/lead time and any vendor constraints.\n• Any unquoted service or required clarification; do not assume it is included.";
        $body = 'Dear '.$name.",\n\n".$p['opening']."\n\nRequest: ".$rfq->reference.' · revision '.$revision->number."\n".self::facts($rfq, $p)."\n\n".$checklist;
        if ($p['vendor_notes']) {
            $body .= "\n\nVENDOR REQUEST NOTES\n".$p['vendor_notes'];
        }
        if ($p['alternative_notes']) {
            $body .= "\n\nALTERNATIVE REQUEST (quote separately; baseline above remains unchanged)\n".$p['alternative_notes'];
        }
        $body .= "\n\nRequested reply by: ".$deadline." (requested response time, subject to vendor acceptance).\nReply contact: ".($p['company']['reply_name'] ?? 'Not configured').' <'.($p['company']['reply_email'] ?? 'not configured').">\n\n".$p['closing']."\n\n".($p['company']['signature'] ?? 'Company signature not configured');

        return ['reference' => $rfq->reference, 'revision' => $revision->number, 'inquiry_id' => $rfq->inquiry_id, 'vendor_id' => $rfq->vendor_id, 'vendor_name' => $rfq->vendor->company_name,
            'shipment_version_id' => $rfq->round->shipment_version_id, 'shipment_hash' => $rfq->round->version->snapshot_hash, 'to' => $p['to'], 'cc' => $p['cc'],
            'subject' => $p['subject'], 'body' => $body, 'html' => '<div style="white-space:pre-wrap;font-family:Arial,sans-serif">'.htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>',
            'company' => $p['company'], 'response_due_at' => $p['response_due_at'], 'manifest' => $p['manifest'], 'disclosure' => ['identity' => $p['disclose_identity'], 'addresses' => $p['disclose_addresses'], 'notes' => $p['disclosure_notes']]];
    }
}
