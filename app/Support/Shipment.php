<?php

namespace App\Support;

use App\Models\Inquiry;
use Brick\Math\BigDecimal;

class Shipment
{
    public const SCOPES = ['unknown' => 'Unknown', 'port_to_port' => 'Port to port', 'door_to_port' => 'Door to port', 'port_to_door' => 'Port to door', 'door_to_door' => 'Door to door'];

    public const SERVICES = ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'clearance' => 'Clearance', 'insurance' => 'Insurance', 'storage' => 'Storage', 'handling' => 'Handling'];

    public const SPECIAL = ['dangerous' => 'Dangerous goods', 'temperature' => 'Temperature controlled', 'oversized' => 'Oversized / out of gauge', 'fragile' => 'Special fragile handling', 'other' => 'Other specialist handling'];

    public const CONTAINERS = ['20GP', '40GP', '40HC', '20RF', '40RF', 'Other'];

    public const TEXT = ['cargo_description', 'origin_country', 'origin_location', 'destination_country', 'destination_location', 'pickup_address', 'delivery_address', 'cargo_ready_date', 'arrival_date', 'timing_flexibility', 'special_notes', 'incoterm', 'named_place', 'declared_volume_source', 'goods_currency', 'budget_currency', 'reference_currency'];

    public const DECIMALS = ['declared_volume', 'goods_value', 'budget', 'reference_quote'];

    public static function normalize(array $input, bool $preserveAlternativeRows = false): array
    {
        $out = [];
        foreach (self::TEXT as $key) {
            $out[$key] = isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        }
        foreach (self::DECIMALS as $key) {
            $out[$key] = self::decimal($input[$key] ?? null);
        }
        $out['mode'] = $input['mode'] ?? 'unknown';
        $out['scope'] = $input['scope'] ?? 'unknown';
        foreach (['services', 'special_flags'] as $key) {
            $out[$key] = array_values($input[$key] ?? []);
            sort($out[$key]);
        }
        foreach (['packages' => ['packaging_type', 'quantity', 'gross_weight', 'weight_unit', 'length', 'width', 'height', 'dimension_unit'], 'containers' => ['type', 'quantity', 'gross_weight', 'weight_unit']] as $key => $fields) {
            $out[$key] = [];
            if (! $preserveAlternativeRows && (($key === 'packages' && $out['mode'] !== 'LCL') || ($key === 'containers' && $out['mode'] !== 'FCL'))) {
                continue;
            }
            foreach ($input[$key] ?? [] as $row) {
                if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }
                $clean = [];
                foreach ($fields as $field) {
                    $clean[$field] = in_array($field, ['gross_weight', 'length', 'width', 'height'], true) ? self::decimal($row[$field] ?? null) : ($row[$field] ?? null);
                    if ($field === 'quantity' && $clean[$field] !== null) {
                        $clean[$field] = (int) $clean[$field];
                    }
                }
                $out[$key][] = $clean;
            }
        }

        return $out;
    }

    private static function decimal(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
    }

    public static function snapshot(Inquiry $inquiry): array
    {
        return ['client_id' => $inquiry->client_id === null ? null : (int) $inquiry->client_id, 'client_contact_id' => $inquiry->client_contact_id === null ? null : (int) $inquiry->client_contact_id, 'contact_email' => $inquiry->contact?->email, 'shipment' => self::normalize($inquiry->shipment)];
    }

    public static function warnings(array $shipment): array
    {
        $warnings = [];
        if (! empty($shipment['origin_country']) && ! empty($shipment['origin_location']) && strcasecmp($shipment['origin_country'], $shipment['destination_country'] ?? '') === 0 && strcasecmp($shipment['origin_location'], $shipment['destination_location'] ?? '') === 0) {
            $warnings['shipment.destination_location'] = 'Origin and destination are identical. Check the requested route before confirming.';
        }
        $calculated = self::totals($shipment)['volume'];
        if ($calculated !== null && ! empty($shipment['declared_volume']) && ! BigDecimal::of($calculated)->isEqualTo(BigDecimal::of($shipment['declared_volume']))) {
            $warnings['shipment.declared_volume'] = 'Declared total volume differs from calculated package volume. Review both figures and their sources; neither replaces the other.';
        }
        if (in_array($shipment['scope'] ?? '', ['port_to_port', 'port_to_door'], true) && in_array('pickup', $shipment['services'] ?? [], true)) {
            $warnings['shipment.pickup_address'] = 'Pickup is requested alongside a port-origin scope. Confirm the intended service boundary with the client.';
        }
        if (in_array($shipment['scope'] ?? '', ['port_to_port', 'door_to_port'], true) && in_array('delivery', $shipment['services'] ?? [], true)) {
            $warnings['shipment.delivery_address'] = 'Delivery is requested alongside a port-destination scope. Confirm the intended service boundary with the client.';
        }

        return $warnings;
    }

    public static function totals(array $shipment): array
    {
        $rows = $shipment[$shipment['mode'] === 'FCL' ? 'containers' : 'packages'] ?? [];
        $volume = BigDecimal::of(0);
        $weight = BigDecimal::of(0);
        $completeVolume = $rows !== [];
        $completeWeight = $rows !== [];
        $basis = [];
        foreach ($rows as $row) {
            if (empty($row['gross_weight']) || empty($row['weight_unit'])) {
                $completeWeight = false;
            } else {
                $factor = ['kg' => '1', 't' => '1000', 'lb' => '0.45359237'][$row['weight_unit']];
                $weight = $weight->plus(BigDecimal::of($row['gross_weight'])->multipliedBy($factor));
            }
            if ($shipment['mode'] !== 'LCL' || empty($row['quantity']) || empty($row['length']) || empty($row['width']) || empty($row['height']) || empty($row['dimension_unit'])) {
                $completeVolume = false;

                continue;
            }
            $factor = ['m' => '1', 'cm' => '0.01', 'mm' => '0.001', 'in' => '0.0254'][$row['dimension_unit']];
            $group = BigDecimal::of($row['quantity']);
            foreach (['length', 'width', 'height'] as $field) {
                $group = $group->multipliedBy(BigDecimal::of($row[$field])->multipliedBy($factor));
            }
            $volume = $volume->plus($group);
            $basis[] = $row['quantity'].' × '.$row['length'].' × '.$row['width'].' × '.$row['height'].' '.$row['dimension_unit'].' = '.$group->strippedOfTrailingZeros().' m³';
        }

        return ['volume' => $completeVolume ? (string) $volume->strippedOfTrailingZeros() : null, 'weight' => $completeWeight ? (string) $weight->strippedOfTrailingZeros() : null, 'basis' => $basis];
    }
}
