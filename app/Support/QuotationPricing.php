<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class QuotationPricing
{
    public static function calculate(array $basis, array $p): array
    {
        $currency = $basis['comparison']['currency'];
        $gaps = [];
        $cost = BigDecimal::zero();
        $vendorTax = BigDecimal::zero();
        $costLines = [];
        foreach ($basis['calculation']['lines'] as $line) {
            if (! empty($line['optional']) || $line['state'] !== 'priced') {
                continue;
            }
            $amount = $line['subtotal'];
            $treatment = $line['tax_treatment'] ?? 'unknown';
            if ($amount === null || $treatment === 'unknown') {
                $gaps[] = 'Resolve the tax/cost basis for '.$line['description'].'.';

                continue;
            }
            if ($treatment === 'inclusive') {
                $rate = $line['tax_rate'] ?? ($p['vendor_tax_rates'][$line['key']] ?? null);
                $rate = OfferCosts::decimal($rate);
                if ($rate === null || empty($p['vendor_tax_evidence'])) {
                    $gaps[] = 'Confirm the embedded vendor tax rate and evidence for '.$line['description'].'.';

                    continue;
                }
                if (BigDecimal::of($rate)->isGreaterThan('100')) {
                    Processing::fail('Vendor tax rate must be between 0 and 100.');
                }
                $amount = OfferCosts::money((string) BigDecimal::of($amount)->dividedBy(BigDecimal::one()->plus(BigDecimal::of($rate)->dividedBy('100', 12, RoundingMode::HalfUp)), 16, RoundingMode::HalfUp), $line['currency']);
            }
            $nativeTax = (string) BigDecimal::of($line['amount'])->minus($amount);
            $convert = function (string $value) use ($basis, $line, $currency): ?string {
                $native = OfferCosts::fx($value, $line['currency'], $basis['calculation']['currency'], $basis['calculation']['fx']);

                return $native === null ? null : OfferCosts::fx($native, $basis['calculation']['currency'], $currency, $basis['comparison']['fx']);
            };
            $converted = $convert($amount);
            $tax = $convert($nativeTax);
            if ($converted === null || $tax === null) {
                $gaps[] = 'Reviewed currency conversion is missing.';

                continue;
            }
            $cost = $cost->plus($converted);
            $vendorTax = $vendorTax->plus($tax);
            $description = $p['descriptions'][$line['key']] ?? null;
            if (! $description) {
                $gaps[] = 'Enter a customer service description for '.$line['description'].'.';
            }
            $costLines[] = ['key' => $line['key'], 'description' => $description, 'cost' => $converted, 'vendor_tax' => $tax, 'quantity' => $line['billed_quantity'], 'basis' => $line['basis'], 'native_currency' => $line['currency'], 'native_subtotal' => $amount];
        }
        $markup = OfferCosts::decimal($p['markup_percent'] ?? null);
        if ($markup === null) {
            $gaps[] = 'Enter an explicit markup percentage.';
        } elseif (BigDecimal::of($markup)->isGreaterThan('1000')) {
            Processing::fail('Markup must be between 0 and 1000 percent.');
        }
        if (empty($p['markup_confirmed'])) {
            $gaps[] = 'Staff must explicitly confirm the markup.';
        }
        $cost = OfferCosts::money((string) $cost, $currency);
        $tax = OfferCosts::money((string) $vendorTax, $currency);
        $selling = $markup === null ? null : OfferCosts::money((string) BigDecimal::of($cost)->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($markup)->dividedBy('100', 12, RoundingMode::HalfUp))), $currency);
        $profit = $selling === null ? null : OfferCosts::money((string) BigDecimal::of($selling)->minus($cost), $currency);
        $margin = $selling === null || BigDecimal::of($selling)->isZero() ? null : (string) BigDecimal::of($profit)->multipliedBy('100')->dividedBy($selling, 2, RoundingMode::HalfUp);
        $lines = [];
        $cumulative = BigDecimal::zero();
        $allocated = BigDecimal::zero();
        foreach ($costLines as $line) {
            $price = null;
            if ($selling !== null) {
                $cumulative = $cumulative->plus($line['cost']);
                $rounded = BigDecimal::of(OfferCosts::money((string) $cumulative->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($markup)->dividedBy('100', 12, RoundingMode::HalfUp))), $currency));
                $price = OfferCosts::money((string) $rounded->minus($allocated), $currency);
                $allocated = $rounded;
            }
            $lines[] = ['description' => $line['description'], 'amount' => $price];
        }
        $handling = $p['vendor_tax_handling'] ?? 'unknown';
        if (! in_array($handling, ['recoverable', 'pass_through'], true) || empty($p['vendor_tax_evidence'])) {
            $gaps[] = 'Confirm vendor tax treatment with stated evidence; LRS does not assume recoverability.';
        }
        $passThrough = $handling === 'pass_through' ? $tax : OfferCosts::money('0', $currency);
        if ($handling === 'pass_through' && BigDecimal::of($passThrough)->isGreaterThan('0') && empty($p['tax_charge_description'])) {
            $gaps[] = 'Enter the customer description for the unmarked tax-related charge.';
        }
        $customerTax = null;
        $customerTreatment = $p['customer_tax_treatment'] ?? 'unknown';
        if (! in_array($customerTreatment, ['none', 'exclusive'], true) || empty($p['customer_tax_evidence'])) {
            $gaps[] = 'Resolve the stated customer tax treatment and evidence.';
        } elseif ($selling !== null) {
            $rate = $customerTreatment === 'none' ? '0' : OfferCosts::decimal($p['customer_tax_rate'] ?? null);
            if ($rate === null) {
                $gaps[] = 'Enter the stated customer tax percentage.';
            } elseif (BigDecimal::of($rate)->isGreaterThan('100')) {
                Processing::fail('Customer tax rate must be between 0 and 100.');
            } else {
                $customerTax = OfferCosts::money((string) BigDecimal::of($selling)->plus($passThrough)->multipliedBy($rate)->dividedBy('100', 12, RoundingMode::HalfUp), $currency);
            }
        }
        $total = $selling === null || $customerTax === null ? null : OfferCosts::money((string) BigDecimal::of($selling)->plus($passThrough)->plus($customerTax), $currency);
        $optional = [];
        foreach ($p['optional_lines'] ?? [] as $line) {
            if (empty($line['description']) && empty($line['amount'])) {
                continue;
            }
            $value = OfferCosts::decimal($line['amount'] ?? null);
            if (empty($line['description']) || $value === null) {
                $gaps[] = 'Optional selling lines require a description and explicit price.';

                continue;
            }
            $optional[] = ['description' => $line['description'], 'amount' => OfferCosts::money($value, $currency)];
        }

        return ['schema' => 'lrs-client-pricing-1', 'currency' => $currency, 'cost_subtotal' => $cost, 'vendor_tax' => $tax, 'markup_percent' => $markup, 'markup_amount' => $profit, 'selling_subtotal' => $selling, 'vendor_tax_pass_through' => $passThrough, 'customer_tax' => $customerTax, 'total' => $total, 'estimated_profit' => $profit, 'gross_margin_percent' => $margin, 'cost_lines' => $costLines, 'selling_lines' => $lines, 'optional_lines' => $optional, 'gaps' => array_values(array_unique($gaps)), 'rounding' => 'Half up at native tax separation, each reviewed FX conversion and currency total. Selling lines use cumulative half-up allocation so line amounts stay nonnegative and sum exactly to the subtotal. Optional services excluded from totals.'];
    }
}
