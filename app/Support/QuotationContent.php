<?php

namespace App\Support;

use App\Models\ClientQuotationRevision;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\OfferSelection;
use Carbon\CarbonImmutable;

class QuotationContent
{
    public static function defaults(Inquiry $inquiry, OfferSelection $selection): array
    {
        $basis = $selection->snapshot;
        $s = $basis['shipment']['shipment'];
        $timezone = CompanySetting::current()->timezone;

        return ['offer_selection_id' => $selection->id, 'markup_percent' => null, 'markup_confirmed' => false, 'descriptions' => [],
            'vendor_tax_rates' => [], 'vendor_tax_handling' => 'unknown', 'vendor_tax_evidence' => '', 'tax_charge_description' => '',
            'customer_tax_treatment' => 'unknown', 'customer_tax_rate' => null, 'customer_tax_evidence' => '', 'optional_lines' => [],
            'issue_date' => now($timezone)->toDateString(), 'valid_until' => empty($basis['commercial']['valid_until']) ? null : CarbonImmutable::parse($basis['commercial']['valid_until'])->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'to_contact_id' => $inquiry->client_contact_id, 'cc_contact_ids' => [],
            'subject' => 'Quotation · '.$inquiry->reference.' · '.$s['origin_location'].' to '.$s['destination_location'],
            'body' => 'Dear '.($inquiry->contact?->name ?? 'Customer').",\n\nPlease review the attached quotation for ".$s['mode'].' freight from '.$s['origin_location'].' to '.$s['destination_location'].".\n\nReply with the quotation reference and revision to confirm acceptance or describe requested changes. Acceptance does not confirm a booking; availability and operational arrangements require separate confirmation.\n\nKind regards,\n".CompanySetting::current()->rfq_reply_name,
            'inclusions' => '', 'exclusions' => '', 'conditions' => '', 'terms_confirmed' => false, 'internal_notes' => ''];
    }

    public static function deadline(?string $value, string $timezone): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)->endOfDay() : CarbonImmutable::parse($value, $timezone))->utc();
    }

    public static function customer(array $p, array $pricing, array $basis, string $reference, int $number): array
    {
        $s = $basis['shipment']['shipment'];
        $totals = Shipment::totals($s);
        $rows = $s[$s['mode'] === 'FCL' ? 'containers' : 'packages'] ?? [];
        $groups = [];
        foreach ($rows as $row) {
            $groups[] = ($row['quantity'] ?? '?').' × '.($s['mode'] === 'FCL' ? ($row['container_type'] ?? 'container') : ($row['packaging_type'] ?? 'packages'));
        }
        $summary = implode('; ', $groups);
        if ($totals['weight'] !== null) {
            $summary .= ' · '.$totals['weight'].' kg total gross weight';
        }
        if ($totals['volume'] !== null) {
            $summary .= ' · '.$totals['volume'].' CBM calculated volume';
        }

        return ['schema' => 'lrs-customer-quotation-1', 'reference' => $reference, 'revision' => $number, 'company' => $p['company'],
            'client' => $p['client'], 'to' => $p['to'], 'issue_date' => $p['issue_date'], 'valid_until' => $p['deadline'],
            'shipment' => array_intersect_key($s, array_flip(['mode', 'scope', 'origin_country', 'origin_location', 'destination_country', 'destination_location', 'cargo_description', 'incoterm', 'named_place', 'cargo_ready_date', 'arrival_date'])),
            'quantity_summary' => $summary,
            'currency' => $pricing['currency'], 'lines' => $pricing['selling_lines'], 'optional_lines' => $pricing['optional_lines'],
            'selling_subtotal' => $pricing['selling_subtotal'], 'tax_charge' => $pricing['vendor_tax_pass_through'],
            'tax_charge_description' => $p['tax_charge_description'], 'tax' => $pricing['customer_tax'], 'tax_rate' => $p['customer_tax_treatment'] === 'none' ? '0' : $p['customer_tax_rate'],
            'total' => $pricing['total'], 'inclusions' => $p['inclusions'], 'exclusions' => $p['exclusions'], 'conditions' => $p['conditions'],
            'instructions' => 'Reply with this reference and revision to accept or request changes. Acceptance does not confirm a booking. Estimated transit and dates remain subject to separate operational confirmation.'];
    }

    public static function mail(ClientQuotationRevision $r): array
    {
        $p = $r->payload;
        $deadline = $r->expires_at ? $r->expires_at->setTimezone($p['company']['timezone'])->format('d M Y, H:i').' '.$p['company']['timezone'] : 'Unresolved';

        return ['reference' => $r->quotation->reference, 'revision' => $r->number, 'inquiry_id' => $r->quotation->inquiry_id,
            'vendor_name' => 'Client quotation', 'to' => $p['to'], 'cc' => $p['cc'], 'subject' => $p['subject'],
            'body' => $p['body']."\n\nQuotation: ".$r->quotation->reference.' · revision '.$r->number."\nValid until: ".$deadline."\nSelling total: ".$r->pricing['currency'].' '.$r->pricing['total']."\nOptional services are excluded from the total. Reply with this reference and revision to accept or request changes. Acceptance does not confirm a booking.",
            'company' => $p['company'], 'manifest' => [['quotation_revision_id' => $r->id, 'version' => $r->number, 'checksum' => $r->pdf_checksum, 'name' => $r->quotation->reference.'-v'.$r->number.'.pdf', 'mime' => 'application/pdf', 'size' => $r->pdf_size, 'scan_status' => 'Generated locally from reviewed customer content']]];
    }
}
