<?php

namespace Tests\Unit;

use App\Support\QuotationContent;
use App\Support\QuotationPricing;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuotationPricingTest extends TestCase
{
    private function basis(string $cost = '1000', string $currency = 'MYR'): array
    {
        return ['comparison' => ['currency' => $currency, 'fx' => []], 'calculation' => ['currency' => $currency, 'fx' => [], 'lines' => [
            ['key' => 'freight', 'description' => 'Freight', 'optional' => false, 'state' => 'priced', 'subtotal' => $cost, 'amount' => $cost, 'tax_treatment' => 'inclusive', 'tax_rate' => '0', 'currency' => $currency, 'billed_quantity' => '1', 'basis' => 'flat'],
            ['key' => 'option', 'description' => 'Insurance', 'optional' => true, 'state' => 'priced', 'subtotal' => '90', 'amount' => '90', 'tax_treatment' => 'inclusive', 'tax_rate' => '0', 'currency' => $currency, 'billed_quantity' => '1', 'basis' => 'flat'],
        ]]];
    }

    private function input(): array
    {
        return ['markup_percent' => '20', 'markup_confirmed' => true, 'descriptions' => ['freight' => 'Ocean freight service'], 'vendor_tax_evidence' => 'Explicit fixture rate: zero tax', 'vendor_tax_handling' => 'recoverable', 'customer_tax_treatment' => 'none', 'customer_tax_evidence' => 'Explicit fixture treatment: no customer tax'];
    }

    public function test_markup_and_margin_are_distinct_and_optional_costs_are_excluded(): void
    {
        $r = QuotationPricing::calculate($this->basis(), $this->input());
        $this->assertSame([], $r['gaps']);
        $this->assertSame('1000.00', $r['cost_subtotal']);
        $this->assertSame('1200.00', $r['selling_subtotal']);
        $this->assertSame('200.00', $r['estimated_profit']);
        $this->assertSame('16.67', $r['gross_margin_percent']);
        $this->assertSame('1200.00', $r['total']);
        $this->assertCount(1, $r['selling_lines']);
    }

    public function test_vendor_tax_receives_no_markup_and_explicit_nonrecoverable_tax_passes_through(): void
    {
        $b = $this->basis('1100');
        $b['calculation']['lines'][0]['tax_rate'] = '10';
        $p = $this->input() + ['tax_charge_description' => 'Stated supplementary charge'];
        $p['vendor_tax_handling'] = 'pass_through';
        $p['customer_tax_treatment'] = 'exclusive';
        $p['customer_tax_rate'] = '6';
        $r = QuotationPricing::calculate($b, $p);
        $this->assertSame('1000.00', $r['cost_subtotal']);
        $this->assertSame('100.00', $r['vendor_tax']);
        $this->assertSame('1200.00', $r['selling_subtotal']);
        $this->assertSame('78.00', $r['customer_tax']);
        $this->assertSame('1378.00', $r['total']);
        $this->assertSame('200.00', $r['estimated_profit']);
    }

    public function test_unresolved_inclusive_tax_customer_tax_and_fx_remain_visible_gaps(): void
    {
        $b = $this->basis();
        unset($b['calculation']['lines'][0]['tax_rate']);
        $p = $this->input();
        $p['customer_tax_treatment'] = 'unknown';
        $r = QuotationPricing::calculate($b, $p);
        $this->assertNull($r['total']);
        $this->assertNotEmpty($r['gaps']);
        $b = $this->basis();
        $b['comparison']['currency'] = 'USD';
        $this->assertStringContainsString('conversion', implode(' ', QuotationPricing::calculate($b, $this->input())['gaps']));
    }

    public function test_exact_half_up_currency_rounding_and_zero_margin(): void
    {
        $r = QuotationPricing::calculate($this->basis('0'), $this->input());
        $this->assertSame('0.00', $r['total']);
        $this->assertNull($r['gross_margin_percent']);
        $p = $this->input();
        $p['markup_percent'] = '0.5';
        $this->assertSame('1.01', QuotationPricing::calculate($this->basis('1'), $p)['total']);
        $this->assertSame('1200', QuotationPricing::calculate($this->basis('1000', 'JPY'), $this->input())['total']);
        $this->assertSame('1.200', QuotationPricing::calculate($this->basis('1', 'KWD'), $this->input())['total']);
    }

    public function test_unsupported_negative_exponential_and_out_of_range_markup_are_rejected(): void
    {
        foreach (['-1', '1e3', '1000.01', 'NaN', 20.1] as $value) {
            try {
                $p = $this->input();
                $p['markup_percent'] = $value;
                QuotationPricing::calculate($this->basis(), $p);
                $this->fail('Accepted unsupported markup');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_date_only_expiry_uses_company_local_end_of_day(): void
    {
        $d = QuotationContent::deadline('2026-10-24', 'Asia/Kuala_Lumpur');
        $this->assertSame('2026-10-24 15:59:59', $d->format('Y-m-d H:i:s'));
    }

    public function test_cumulative_rounding_keeps_tiny_and_zero_lines_nonnegative_and_balanced(): void
    {
        $b = $this->basis('0.01');
        $p = $this->input();
        $p['markup_percent'] = '50';
        $line = $b['calculation']['lines'][0];
        $b['calculation']['lines'] = [];
        for ($i = 0; $i < 4; $i++) {
            $copy = $line;
            $copy['key'] = 'line-'.$i;
            if ($i === 3) {
                $copy['subtotal'] = '0';
                $copy['amount'] = '0';
            }
            $b['calculation']['lines'][] = $copy;
            $p['descriptions'][$copy['key']] = 'Service '.$i;
        }
        $r = QuotationPricing::calculate($b, $p);
        $this->assertSame('0.05', $r['selling_subtotal']);
        $this->assertSame(['0.02', '0.01', '0.02', '0.00'], array_column($r['selling_lines'], 'amount'));
        $this->assertSame('0.02', $r['estimated_profit']);
        $this->assertSame([], $r['gaps']);
    }
}
