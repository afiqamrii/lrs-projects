<?php

namespace App\Http\Requests;

use App\Support\OfferCosts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    public static function commercialRules(): array
    {
        $decimal = ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D'];
        $rules = ['expected_revision' => 'required|integer|min:0', 'change_reason' => 'required|string|max:2000', 'reference' => 'required|string|max:200', 'issued_on' => 'nullable|date_format:Y-m-d', 'received_on' => 'required|date_format:Y-m-d', 'currency' => ['required', Rule::in(array_keys(config('offers.currency_precision')))], 'mode' => ['required', Rule::in(['LCL', 'FCL'])], 'scope' => ['required', Rule::in(['unknown', 'port_to_port', 'port_to_door', 'door_to_port', 'door_to_door'])], 'quantity_statement' => 'nullable|string|max:2000', 'volume_basis' => ['nullable', Rule::in(['calculated', 'declared'])], 'payment_terms' => 'nullable|string|max:2000', 'valid_until' => 'nullable|date', 'validity_statement' => ['required', Rule::in(['dated', 'unknown', 'open_ended', 'resolved'])], 'validity_resolution' => 'nullable|string|max:2000', 'timing_assessment' => ['required', Rule::in(['pending', 'meets_requested', 'not_met'])], 'timing_note' => 'nullable|string|max:2000', 'transit' => 'nullable|string|max:2000', 'conditions' => 'nullable|string|max:4000', 'inclusions' => 'nullable|string|max:2000', 'exclusions' => 'nullable|string|max:2000', 'total_not_stated_reason' => 'nullable|string|max:2000', 'quoted_total' => $decimal, 'quoted_subtotal' => $decimal, 'lines' => 'required|array|min:1|max:50', 'review' => 'nullable|array', 'fx' => 'nullable|array|max:12'];
        foreach (['origin_country', 'origin_location', 'destination_country', 'destination_location'] as $key) {
            $rules[$key] = 'nullable|string|max:500';
        }
        foreach (['scope', 'quantities', 'terms', 'validity'] as $key) {
            $rules['review.'.$key] = 'nullable|boolean';
        }
        $rules += ['lines.*.key' => 'required|string|max:80|distinct', 'lines.*.description' => 'required|string|max:500', 'lines.*.category' => ['required', Rule::in(array_keys(OfferCosts::CATEGORIES))], 'lines.*.service' => ['required', Rule::in(array_keys(OfferCosts::SERVICES))], 'lines.*.state' => ['required', Rule::in(array_keys(OfferCosts::STATES))], 'lines.*.basis' => ['required', Rule::in(array_keys(OfferCosts::BASES))], 'lines.*.currency' => ['required', Rule::in(array_keys(config('offers.currency_precision')))], 'lines.*.tax_treatment' => ['required', Rule::in(['unknown', 'inclusive', 'exclusive', 'not_applicable'])]];
        foreach (['rate', 'minimum_charge', 'minimum_quantity', 'custom_quantity', 'wm_kg', 'wm_cbm', 'tax_rate', 'billing_increment'] as $key) {
            $rules['lines.*.'.$key] = $decimal;
        }
        foreach (['container_type', 'unit_definition', 'included_in', 'arrangement_for', 'arrangement_evidence', 'source_ref', 'raw_text', 'zero_evidence'] as $key) {
            $rules['lines.*.'.$key] = 'nullable|string|max:2000';
        }
        foreach (['confirmed', 'optional'] as $key) {
            $rules['lines.*.'.$key] = 'nullable|boolean';
        }
        $rules += ['fx.*.from' => ['required', Rule::in(array_keys(config('offers.currency_precision')))], 'fx.*.to' => ['required', Rule::in(array_keys(config('offers.currency_precision')))], 'fx.*.rate' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D'], 'fx.*.direction' => 'required|in:multiply,divide', 'fx.*.date' => 'required|date_format:Y-m-d', 'fx.*.source' => 'required|string|max:1000', 'fx.*.confirmed' => 'required|accepted'];

        return $rules;
    }

    public function prepareForValidation(): void
    {
        $this->merge(['fx' => self::filledFx($this->input('fx', []))]);
    }

    public static function filledFx(?array $fx): array
    {
        return array_filter($fx ?? [], fn (array $f): bool => ! empty($f['rate']) || ! empty($f['date']) || ! empty($f['source']) || ! empty($f['confirmed']));
    }

    public function rules(): array
    {
        return self::commercialRules();
    }

    public static function commercialAttributes(): array
    {
        return ['fx.*.from' => 'source currency', 'fx.*.to' => 'target currency', 'fx.*.rate' => 'exchange rate', 'fx.*.direction' => 'conversion direction', 'fx.*.date' => 'rate date', 'fx.*.source' => 'rate source', 'fx.*.confirmed' => 'exchange-rate review confirmation'];
    }

    public function attributes(): array
    {
        return self::commercialAttributes();
    }
}
