<?php

namespace App\Support;

use App\Models\AiRun;
use Brick\Math\BigDecimal;

class ProposalEvidence
{
    public static function inspect(AiRun $run, array $result): array
    {
        ProposalSchema::validate($result);
        $sources = collect($run->sources)->keyBy('id');
        $proposals = [];
        $byField = collect($result['candidates'])->groupBy('field');
        foreach ($result['candidates'] as $index => $candidate) {
            $warnings = $candidate['warnings'];
            $supported = count($candidate['evidence']) > 0;
            $quotes = [];
            foreach ($candidate['evidence'] as $evidence) {
                $source = $sources->get($evidence['source_id']);
                $quote = self::text($evidence['quote']);
                if (! $source || $source['locator'] !== $evidence['locator'] || mb_strlen($quote) < 2 || ! str_contains(self::text($source['text']), $quote)) {
                    $supported = false;
                    $warnings[] = 'The source ID, locator or supporting quotation could not be verified.';
                } else {
                    $quotes[] = $quote;
                    $warnings = [...$warnings, ...$source['warnings']];
                }
            }
            $joined = implode(' ', $quotes);
            $ambiguous = $candidate['ambiguous'] || $candidate['value'] === null || $candidate['value'] === 'unknown';
            if (preg_match('/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b|\d+,\d{3}\b|\$(?!\s*(USD|SGD|AUD|HKD))/i', $candidate['raw_value'])) {
                $ambiguous = true;
                $warnings[] = 'A date, decimal separator or currency symbol needs explicit human interpretation.';
            }
            $field = $candidate['field'];
            if (! self::literalValues($candidate['value'], $joined)) {
                $supported = false;
                $warnings[] = 'The proposed literal value is not present in the supporting quote. Use documented manual correction for an interpretation or paraphrase.';
            }
            $criticalNumeric = in_array($field, [...Shipment::DECIMALS, 'packages', 'containers'], true);
            if ($criticalNumeric && ! self::numbers($candidate['value'], $joined)) {
                $supported = false;
                $warnings[] = 'A normalized number or unit is not deterministically supported by the quoted passage.';
            }
            $roleWarnings = self::roleWarnings($field, $candidate['value'], $joined);
            if ($roleWarnings) {
                $ambiguous = true;
                $warnings = [...$warnings, ...$roleWarnings];
            }
            $current = $run->working_snapshot['shipment'][$field] ?? null;
            $populated = $current !== null && $current !== '' && $current !== [] && $current !== 'unknown';
            $conflict = ($populated && Processing::hash(['v' => $current]) !== Processing::hash(['v' => $candidate['value']])) || $byField[$field]->pluck('value')->map(fn (mixed $value): string => Processing::hash(['v' => $value]))->unique()->count() > 1;
            if ($conflict) {
                $warnings[] = 'Compare the current value and all candidates for this field; no source takes precedence automatically.';
            }
            $proposals[] = [...$candidate, 'id' => (string) ($index + 1), 'supported' => $supported, 'ambiguous' => $ambiguous, 'conflict' => $conflict, 'current_value' => $current, 'warnings' => array_values(array_unique($warnings)), 'label' => ! $supported ? 'Missing evidence' : ($ambiguous ? 'Ambiguous' : ($conflict ? 'Conflicts with current value' : 'Supported by source'))];
        }

        return $proposals;
    }

    private static function roleWarnings(string $field, mixed $value, string $quotes): array
    {
        $warnings = [];
        if (in_array($field, ['packages', 'containers'], true) && is_array($value) && count($value) !== count(array_unique(array_map(fn (array $row): string => Processing::hash($row), $value)))) {
            $warnings[] = 'Likely duplicated shipment rows. Compare each group and repeated headings against the original before manual correction.';
        }
        if (preg_match('/\\b(?:multiple shipments|shipment\\s*(?:2|B)|second shipment)\\b/i', $quotes)) {
            $warnings[] = 'Possible multiple shipments. Keep source groups distinct and clarify the intended shipment manually.';
        }
        if (in_array($field, ['goods_value', 'goods_currency'], true) && preg_match('/\\b(?:freight (?:quote|cost|price)|client budget)\\b/i', $quotes)) {
            $warnings[] = 'Freight/reference prices and client budgets are not goods invoice values. Resolve the amount role manually.';
        }
        if (in_array($field, ['reference_quote', 'reference_currency'], true) && preg_match('/\\b(?:goods invoice|goods value|client budget)\\b/i', $quotes)) {
            $warnings[] = 'Goods invoice value and client budget are not a reference freight quotation. Resolve the amount role manually.';
        }
        if (in_array($field, ['origin_location', 'origin_country', 'pickup_address'], true) && preg_match('/\\b(?:seller address|bill to|registered address)\\b/i', $quotes)) {
            $warnings[] = 'An invoice or seller address does not establish the shipment origin or pickup location.';
        }
        if ($field === 'requested_arrival_date' && preg_match('/\\b(?:confirmed arrival|transit time)\\b/i', $quotes)) {
            $warnings[] = 'Requested arrival is distinct from confirmed transit or arrival. Clarify the date role manually.';
        }

        return $warnings;
    }

    public static function text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));
    }

    private static function numbers(mixed $value, string $quotes): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (in_array($key, ['weight_unit', 'dimension_unit'], true) && $item !== null && ! preg_match('/(?<!\pL)'.preg_quote((string) $item, '/').'(?!\pL)/ui', $quotes)) {
                    return false;
                }
                if (! self::numbers($item, $quotes)) {
                    return false;
                }
            }

            return true;
        }
        if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return true;
        }
        preg_match_all('/(?<![\d.,])\d+(?:\.\d+)?(?![\d.,])/', $quotes, $matches);
        foreach ($matches[0] as $raw) {
            if (BigDecimal::of($value)->isEqualTo(BigDecimal::of($raw))) {
                return true;
            }
        }

        return false;
    }

    private static function literalValues(mixed $value, string $quotes): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::literalValues($item, $quotes)) {
                    return false;
                }
            }

            return true;
        }
        if (! is_string($value) || preg_match('/^\\d+(\\.\\d+)?$/', $value)) {
            return true;
        }
        $normalized = mb_strtolower(self::text(str_replace('_', ' ', $value)));
        $source = mb_strtolower(self::text($quotes));

        return $normalized !== '' && str_contains($source, $normalized);
    }

    public static function display(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Unknown';
        }
        if (! is_array($value)) {
            return (string) $value;
        }
        if (! is_array($value[0] ?? null)) {
            return implode(', ', $value);
        }
        $groups = [];
        foreach ($value as $index => $row) {
            $pieces = [];
            foreach ($row as $key => $item) {
                $pieces[] = ucfirst(str_replace('_', ' ', $key)).': '.($item === null || $item === '' ? 'Unknown' : $item);
            }
            $groups[] = 'Group '.($index + 1).' — '.implode(' · ', $pieces);
        }

        return implode("\n", $groups);
    }
}
