<?php

namespace Tests\Unit;

use App\Support\OfferCosts;
use App\Support\OfferProposal;
use App\Support\Shipment;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfferCostsTest extends TestCase
{
    private function shipment(): array
    {
        return Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_port', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '3000', 'weight_unit' => 'kg', 'length' => '100', 'width' => '100', 'height' => '100', 'dimension_unit' => 'cm']]]);
    }

    private function line(array $extra = []): array
    {
        return array_replace(['key' => 'line-1', 'description' => 'Main freight', 'category' => 'main', 'service' => 'freight', 'state' => 'priced', 'basis' => 'flat', 'currency' => 'MYR', 'rate' => '100', 'tax_treatment' => 'inclusive', 'confirmed' => true, 'optional' => false, 'source_ref' => 'quote page 1 line 1'], $extra);
    }

    private function payload(array $lines, array $extra = []): array
    {
        return array_replace(array_intersect_key($this->shipment(), array_flip(['mode', 'scope', 'origin_country', 'origin_location', 'destination_country', 'destination_location'])), ['currency' => 'MYR', 'quantity_statement' => '2 pallets, 2 CBM, 3000 kg', 'volume_basis' => 'calculated', 'timing_assessment' => 'meets_requested', 'timing_note' => 'Vendor-confirmed requested dates', 'payment_terms' => 'Prepaid', 'validity_statement' => 'dated', 'valid_until' => '2027-01-01T00:00:00Z', 'total_not_stated_reason' => 'Rate card has no stated total', 'review' => ['scope' => true, 'quantities' => true, 'terms' => true, 'validity' => true], 'lines' => $lines], $extra);
    }

    public function test_decimal_rounding_never_uses_binary_money_and_currency_precision_is_explicit(): void
    {
        $c = OfferCosts::calculate($this->payload([$this->line(['rate' => '0.1']), $this->line(['key' => 'line-2', 'rate' => '0.2', 'source_ref' => 'line2'])]), $this->shipment());
        $this->assertSame('0.30', $c['complete_total']);
        $this->assertSame('1.01', OfferCosts::money('1.005', 'MYR'));
        $this->assertSame('101', OfferCosts::money('100.5', 'JPY'));
        $this->assertSame('1.235', OfferCosts::money('1.2345', 'KWD'));
    }

    public function test_cbm_minimum_charge_and_minimum_quantity_are_separate(): void
    {
        $l = $this->line(['basis' => 'cbm', 'rate' => '100', 'minimum_quantity' => '3', 'minimum_charge' => '350']);
        $c = OfferCosts::calculate($this->payload([$l]), $this->shipment());
        $this->assertSame('350.00', $c['complete_total']);
        $this->assertSame('2', $c['lines'][0]['quantity']);
        $this->assertSame('3', $c['lines'][0]['billed_quantity']);
    }

    public function test_container_and_explicit_wm_definitions_use_confirmed_cargo(): void
    {
        $s = $this->shipment();
        $s['mode'] = 'FCL';
        $s['packages'] = [];
        $s['containers'] = [['type' => '40HC', 'quantity' => 2, 'gross_weight' => '12000', 'weight_unit' => 'kg']];
        $p = $this->payload([$this->line(['basis' => 'container', 'container_type' => '40HC', 'rate' => '1200'])], ['mode' => 'FCL']);
        $this->assertSame('2400.00', OfferCosts::calculate($p, $s)['complete_total']);
        $p = $this->payload([$this->line(['basis' => 'wm', 'wm_kg' => '1000', 'wm_cbm' => '1', 'rate' => '100'])]);
        $this->assertSame('300.00', OfferCosts::calculate($p, $this->shipment())['complete_total']);
        unset($p['lines'][0]['wm_kg']);
        $c = OfferCosts::calculate($p, $this->shipment());
        $this->assertNull($c['complete_total']);
        $this->assertNull($c['lines'][0]['amount']);
    }

    public function test_fx_direction_missing_rates_and_mixed_currency_are_explicit(): void
    {
        $f = ['USD' => ['from' => 'USD', 'to' => 'MYR', 'rate' => '4.5', 'direction' => 'multiply', 'date' => '2026-10-05', 'source' => 'Fictional test rate', 'confirmed' => true]];
        $this->assertSame('450.00', OfferCosts::fx('100', 'USD', 'MYR', $f));
        $f['USD']['rate'] = '0.22222222';
        $f['USD']['direction'] = 'divide';
        $this->assertSame('450.00', OfferCosts::fx('100', 'USD', 'MYR', $f));
        $this->assertNull(OfferCosts::fx('100', 'USD', 'MYR', []));
        $p = $this->payload([$this->line(['currency' => 'USD']), $this->line(['key' => 'line-2', 'source_ref' => 'line2', 'rate' => '50'])]);
        $this->assertNull(OfferCosts::calculate($p, $this->shipment())['complete_total']);
        $p['fx'] = ['USD' => ['from' => 'USD', 'to' => 'MYR', 'rate' => '4.5', 'direction' => 'multiply', 'date' => '2026-10-05', 'source' => 'Fictional test rate', 'confirmed' => true]];
        $this->assertSame('500.00', OfferCosts::calculate($p, $this->shipment())['complete_total']);
    }

    public function test_included_optional_and_duplicate_sources_do_not_create_false_totals(): void
    {
        $lines = [$this->line(), $this->line(['key' => 'line-2', 'source_ref' => 'line2', 'description' => 'Included handling', 'service' => 'handling', 'state' => 'included', 'included_in' => 'line-1']), $this->line(['key' => 'line-3', 'source_ref' => 'line3', 'description' => 'Optional insurance', 'service' => 'insurance', 'optional' => true, 'rate' => '75'])];
        $c = OfferCosts::calculate($this->payload($lines), $this->shipment());
        $this->assertSame('100.00', $c['complete_total']);
        $this->assertSame('75.00', $c['optional_total']);
        $lines[1]['source_ref'] = $lines[0]['source_ref'];
        $this->assertNull(OfferCosts::calculate($this->payload($lines), $this->shipment())['complete_total']);
    }

    public function test_tax_quoted_difference_and_zero_evidence_are_not_assumed(): void
    {
        $p = $this->payload([$this->line(['tax_treatment' => 'exclusive', 'tax_rate' => '6'])], ['quoted_subtotal' => '100', 'quoted_total' => '106']);
        $this->assertSame('106.00', OfferCosts::calculate($p, $this->shipment())['complete_total']);
        $p['quoted_total'] = '106.01';
        $this->assertNotNull(OfferCosts::calculate($p, $this->shipment())['complete_total']);
        $p['quoted_total'] = '107';
        $this->assertNull(OfferCosts::calculate($p, $this->shipment())['complete_total']);
        $p = $this->payload([$this->line(['rate' => '0'])]);
        $this->assertNull(OfferCosts::calculate($p, $this->shipment())['complete_total']);
        $p['lines'][0]['zero_evidence'] = 'Vendor explicitly waived freight';
        $this->assertSame('0.00', OfferCosts::calculate($p, $this->shipment())['complete_total']);
    }

    public function test_required_excluded_delivery_needs_full_reviewed_arrangement(): void
    {
        $s = $this->shipment();
        $s['scope'] = 'port_to_door';
        $lines = [$this->line(), $this->line(['key' => 'line-2', 'source_ref' => 'line2', 'service' => 'delivery', 'state' => 'excluded']), $this->line(['key' => 'line-3', 'source_ref' => 'local transport quote line1', 'service' => 'delivery', 'rate' => '80', 'arrangement_for' => 'line-2', 'arrangement_evidence' => 'Named local provider quote LP-123 full delivery cost'])];
        $p = $this->payload($lines, ['scope' => 'port_to_door', 'quoted_total' => '100']);
        $c = OfferCosts::calculate($p, $s);
        $this->assertSame('180.00', $c['complete_total']);
        $this->assertSame('100.00', $c['vendor_total']);
        unset($p['lines'][2]['arrangement_evidence']);
        $this->assertNull(OfferCosts::calculate($p, $s)['complete_total']);
    }

    public function test_unsafe_proposal_fields_and_float_input_are_rejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        OfferProposal::validate(['proposals' => [['field' => 'selected_vendor', 'value' => '1', 'source_id' => 'x', 'snippet' => 'x', 'uncertainty' => '']]]);
    }

    public function test_floating_money_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        OfferCosts::decimal(0.1);
    }
}
