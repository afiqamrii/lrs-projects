<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OfferCosts
{
    public const CATEGORIES = ['main' => 'Main freight', 'origin' => 'Origin', 'destination' => 'Destination', 'pickup_delivery' => 'Pickup / delivery', 'clearance' => 'Clearance', 'insurance' => 'Insurance', 'other' => 'Other'];

    public const SERVICES = ['freight' => 'Main freight', 'pickup' => 'Pickup', 'delivery' => 'Delivery', 'clearance' => 'Clearance', 'insurance' => 'Insurance', 'storage' => 'Storage', 'handling' => 'Handling', 'other' => 'Other'];

    public const STATES = ['priced' => 'Priced', 'included' => 'Included', 'not_applicable' => 'Not applicable', 'excluded' => 'Excluded', 'missing' => 'Missing', 'unpriced' => 'Applicable · unpriced'];

    public const BASES = ['flat' => 'Flat', 'cbm' => 'Per CBM', 'kg' => 'Per kg', 'tonne' => 'Per tonne', 'container' => 'Per container', 'wm' => 'Weight / measurement', 'custom' => 'Explicit custom unit'];

    public static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            Processing::fail('Commercial numbers must be exact decimal strings.');
        }
        if (! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D', (string) $value)) {
            Processing::fail('Use a non-negative decimal, at most 12 digits and 8 decimal places.');
        }

        return (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
    }

    public static function money(string $value, string $currency): string
    {
        $scale = config('offers.currency_precision.'.$currency);
        if ($scale === null) {
            Processing::fail('Currency precision is not configured for '.$currency.'.');
        }

        $decimal = BigDecimal::of($value);
        if ($decimal->isGreaterThan('999999999999.99999999') || $decimal->isLessThan('0')) {
            Processing::fail('Calculated amount exceeds the supported commercial range; review quantities, rates and FX.');
        }

        return (string) $decimal->toScale($scale, RoundingMode::HalfUp);
    }

    public static function fx(string $amount, string $from, string $to, array $rates): ?string
    {
        if ($from === $to) {
            return self::money($amount, $to);
        }
        $f = $rates[$from] ?? null;
        if (! $f || ($f['from'] ?? null) !== $from || ($f['to'] ?? null) !== $to || empty($f['confirmed']) || empty($f['date']) || empty($f['source']) || ! in_array($f['direction'] ?? null, ['multiply', 'divide'], true)) {
            return null;
        }
        $rate = self::decimal($f['rate'] ?? null);
        if ($rate === null || BigDecimal::of($rate)->isLessThanOrEqualTo('0')) {
            return null;
        }
        $result = $f['direction'] === 'multiply' ? BigDecimal::of($amount)->multipliedBy($rate) : BigDecimal::of($amount)->dividedBy($rate, 16, RoundingMode::HalfUp);

        return self::money((string) $result, $to);
    }

    public static function required(array $s): array
    {
        $required = array_merge(['freight'], $s['services'] ?? []);
        if (in_array($s['scope'] ?? null, ['door_to_port', 'door_to_door'], true)) {
            $required[] = 'pickup';
        }
        if (in_array($s['scope'] ?? null, ['port_to_door', 'door_to_door'], true)) {
            $required[] = 'delivery';
        }

        return array_values(array_unique($required));
    }

    public static function quantity(array $line, array $s, array $p): ?string
    {
        $totals = Shipment::totals($s);
        $basis = $line['basis'];
        if ($basis === 'flat') {
            return '1';
        }
        $volume = ($p['volume_basis'] ?? null) === 'declared' ? ($s['declared_volume'] ?? null) : (($p['volume_basis'] ?? null) === 'calculated' ? $totals['volume'] : null);
        if ($basis === 'cbm') {
            return $volume;
        }
        if ($basis === 'kg') {
            return $totals['weight'];
        }
        if ($basis === 'tonne') {
            return $totals['weight'] === null ? null : (string) BigDecimal::of($totals['weight'])->dividedBy('1000', 12, RoundingMode::HalfUp);
        }
        if ($basis === 'container') {
            $quantity = 0;
            foreach ($s['containers'] ?? [] as $row) {
                if ($row['type'] === ($line['container_type'] ?? null)) {
                    $quantity += (int) $row['quantity'];
                }
            }

            return $quantity > 0 ? (string) $quantity : null;
        }
        if ($basis === 'wm') {
            $kg = self::decimal($line['wm_kg'] ?? null);
            $cbm = self::decimal($line['wm_cbm'] ?? null);
            if (! $kg || ! $cbm || ! $volume || ! $totals['weight']) {
                return null;
            }
            $w = BigDecimal::of($totals['weight'])->dividedBy($kg, 12, RoundingMode::HalfUp);
            $m = BigDecimal::of($volume)->dividedBy($cbm, 12, RoundingMode::HalfUp);

            return (string) ($w->isGreaterThan($m) ? $w : $m);
        }

        return ! empty($line['unit_definition']) ? self::decimal($line['custom_quantity'] ?? null) : null;
    }

    public static function calculate(array $p, array $shipment): array
    {
        $gaps = [];
        $currency = $p['currency'];
        $known = BigDecimal::of('0');
        $optional = BigDecimal::of('0');
        $vendor = BigDecimal::of('0');
        $vendorSubtotal = BigDecimal::of('0');
        $lines = [];
        $covered = [];
        $keys = [];
        $refs = [];
        foreach (['mode', 'scope', 'origin_country', 'origin_location', 'destination_country', 'destination_location'] as $field) {
            if (empty($p[$field]) || strcasecmp(trim($p[$field]), trim($shipment[$field] ?? '')) !== 0) {
                $gaps[] = 'Quoted '.$field.' does not match the confirmed shipment.';
            }
        }
        if (empty($p['quantity_statement'])) {
            $gaps[] = 'Vendor cargo / equipment assumptions have not been recorded.';
        }
        foreach (['scope', 'quantities', 'terms', 'validity'] as $group) {
            if (empty($p['review'][$group])) {
                $gaps[] = 'Explicit '.$group.' review is missing.';
            }
        }
        if (($p['timing_assessment'] ?? 'pending') !== 'meets_requested' || empty($p['timing_note'])) {
            $gaps[] = 'Requested dates / material conditions require explicit timing resolution.';
        }
        if (empty($p['payment_terms'])) {
            $gaps[] = 'Payment terms remain unknown.';
        }
        if (empty($p['valid_until'])) {
            $gaps[] = 'Unknown or open-ended validity requires a vendor-confirmed dated resolution.';
        }
        if (($p['validity_statement'] ?? 'dated') !== 'dated' && empty($p['validity_resolution'])) {
            $gaps[] = 'Retain evidence of the vendor-confirmed validity resolution.';
        }
        foreach ($p['lines'] as $line) {
            $key = $line['key'];
            $label = $line['description'] ?: $key;
            $isOptional = ! empty($line['optional']);
            $amount = null;
            $subtotal = null;
            $quantity = null;
            $billed = null;
            $issues = [];
            if (isset($keys[$key])) {
                $gaps[] = 'Duplicate charge key '.$key.'.';
            }$keys[$key] = true;
            $ref = trim($line['source_ref'] ?? '');
            if ($ref !== '' && isset($refs[$ref])) {
                $gaps[] = 'Duplicate source line '.$ref.'; do not count the same evidence twice.';
            }$refs[$ref] = true;
            if (empty($line['confirmed']) || $ref === '') {
                $issues[] = 'Explicit line review and a source locator are required.';
            }
            $state = $line['state'];
            $service = $line['service'];
            if ($state === 'included') {
                $parent = collect($p['lines'])->first(fn (array $l): bool => $l['key'] === ($line['included_in'] ?? null) && $l['state'] === 'priced' && empty($l['optional']));
                if (! $parent) {
                    $issues[] = 'Included charge must identify its priced baseline parent.';
                } elseif (! $isOptional) {
                    $covered[$service] = true;
                }
            } elseif ($state === 'priced') {
                $quantity = self::quantity($line, $shipment, $p);
                $rate = self::decimal($line['rate'] ?? null);
                if ($quantity === null || BigDecimal::of($quantity)->isLessThanOrEqualTo('0')) {
                    $issues[] = 'Applicable quantity / explicit vendor unit definition is missing.';
                }
                if ($rate === null) {
                    $issues[] = 'Price or rate is missing.';
                }
                if ($rate === '0' && empty($line['zero_evidence'])) {
                    $issues[] = 'Zero requires explicit quoted evidence.';
                }
                if (($line['tax_treatment'] ?? 'unknown') === 'unknown') {
                    $issues[] = 'Tax treatment is unknown.';
                }
                if ($quantity !== null && $rate !== null) {
                    $billed = BigDecimal::of($quantity);
                    $minQty = self::decimal($line['minimum_quantity'] ?? null);
                    if ($minQty !== null && $billed->isLessThan($minQty)) {
                        $billed = BigDecimal::of($minQty);
                    }
                    $step = self::decimal($line['billing_increment'] ?? null);
                    if ($step && BigDecimal::of($step)->isGreaterThan('0')) {
                        $billed = $billed->dividedBy($step, 0, RoundingMode::Ceiling)->multipliedBy($step);
                    }
                    $subtotal = BigDecimal::of($rate)->multipliedBy($billed);
                    $minCharge = self::decimal($line['minimum_charge'] ?? null);
                    if ($minCharge !== null && $subtotal->isLessThan($minCharge)) {
                        $subtotal = BigDecimal::of($minCharge);
                    }
                    $subtotal = self::money((string) $subtotal, $line['currency']);
                    $amount = $subtotal;
                    if (($line['tax_treatment'] ?? null) === 'exclusive') {
                        $tax = self::decimal($line['tax_rate'] ?? null);
                        if ($tax === null) {
                            $issues[] = 'Stated exclusive tax rate is missing.';
                            $amount = null;
                        } else {
                            $taxAmount = self::money((string) BigDecimal::of($subtotal)->multipliedBy($tax)->dividedBy('100', 12, RoundingMode::HalfUp), $line['currency']);
                            $amount = self::money((string) BigDecimal::of($subtotal)->plus($taxAmount), $line['currency']);
                        }
                    }
                }
                if (! empty($line['arrangement_for'])) {
                    $excluded = collect($p['lines'])->first(fn (array $l): bool => $l['key'] === $line['arrangement_for'] && in_array($l['state'], ['excluded', 'unpriced', 'missing'], true) && $l['service'] === $service);
                    if (! $excluded || empty($line['arrangement_evidence'])) {
                        $issues[] = 'Arrangement requires matching unresolved service and full-cost provider evidence.';
                    }
                }
                if (! $isOptional && $amount !== null && $issues === []) {
                    $covered[$service] = true;
                }
            } elseif (in_array($state, ['excluded', 'missing', 'unpriced'], true) && ! $isOptional) {
                $arrangement = collect($p['lines'])->first(fn (array $l): bool => ($l['arrangement_for'] ?? null) === $key && $l['state'] === 'priced' && empty($l['optional']) && ! empty($l['confirmed']) && ! empty($l['arrangement_evidence']));
                if (! $arrangement) {
                    $issues[] = 'Applicable baseline service is '.$state.'.';
                }
            }
            $converted = $amount === null ? null : self::fx($amount, $line['currency'], $currency, $p['fx'] ?? []);
            $convertedSubtotal = $subtotal === null ? null : self::fx($subtotal, $line['currency'], $currency, $p['fx'] ?? []);
            if ($amount !== null && $converted === null) {
                $issues[] = 'Reviewed FX to '.$currency.' is missing.';
            }
            if ($converted !== null) {
                if ($isOptional) {
                    $optional = $optional->plus($converted);
                } else {
                    $known = $known->plus($converted);
                    if (empty($line['arrangement_for'])) {
                        $vendor = $vendor->plus($converted);
                        $vendorSubtotal = $vendorSubtotal->plus($convertedSubtotal ?? '0');
                    }
                }
            }
            foreach ($issues as $issue) {
                if (! $isOptional) {
                    $gaps[] = $label.': '.$issue;
                }
            }
            $lines[] = $line + ['quantity' => $quantity, 'billed_quantity' => $billed === null ? null : (string) $billed, 'subtotal' => $subtotal, 'amount' => $amount, 'converted' => $converted, 'issues' => $issues];
        }
        foreach (self::required($shipment) as $service) {
            if (empty($covered[$service])) {
                $gaps[] = 'Required '.(self::SERVICES[$service] ?? $service).' has no reviewed, fully priced or included coverage.';
            }
        }
        $known = self::money((string) $known, $currency);
        $vendor = self::money((string) $vendor, $currency);
        $scale = config('offers.currency_precision.'.$currency);
        $tolerance = (string) BigDecimal::of('1')->dividedBy('1'.str_repeat('0', $scale), $scale, RoundingMode::HalfUp);
        foreach (['quoted_total' => $vendor, 'quoted_subtotal' => self::money((string) $vendorSubtotal, $currency)] as $field => $calculated) {
            $quoted = self::decimal($p[$field] ?? null);
            if ($quoted !== null && BigDecimal::of($quoted)->minus($calculated)->abs()->isGreaterThan($tolerance)) {
                $gaps[] = ucwords(str_replace('_', ' ', $field)).' differs materially from calculated vendor charges. Resolve the actual lines.';
            }
        }
        if (empty($p['quoted_total']) && ($p['quoted_total'] ?? null) !== '0' && empty($p['total_not_stated_reason'])) {
            $gaps[] = 'Confirm that the vendor did not state a total, or enter the original quoted total.';
        }
        $gaps = array_values(array_unique($gaps));

        return ['version' => 'offer-costs-1', 'currency' => $currency, 'known_total' => $known, 'complete_total' => $gaps === [] ? $known : null, 'vendor_total' => $vendor, 'vendor_subtotal' => self::money((string) $vendorSubtotal, $currency), 'optional_total' => self::money((string) $optional, $currency), 'required_services' => self::required($shipment), 'lines' => $lines, 'fx' => $p['fx'] ?? [], 'gaps' => $gaps, 'rounding' => 'Half up at each native charge, stated tax and conversion; one minor unit reconciliation tolerance.', 'tolerance' => $tolerance];
    }
}
