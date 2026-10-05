<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    public function rules(): array
    {
        return self::quotationRules();
    }

    public static function quotationRules(): array
    {
        $decimal = ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D'];

        return [
            'expected_revision' => 'required|integer|min:0', 'offer_selection_id' => 'required|integer|exists:offer_selections,id',
            'change_reason' => 'required|string|max:2000', 'intent' => ['required', Rule::in(['draft', 'review'])],
            'markup_percent' => $decimal, 'markup_confirmed' => 'nullable|boolean',
            'descriptions' => 'nullable|array|max:50', 'descriptions.*' => 'nullable|string|max:500',
            'vendor_tax_rates' => 'nullable|array|max:50', 'vendor_tax_rates.*' => $decimal,
            'vendor_tax_handling' => ['required', Rule::in(['unknown', 'recoverable', 'pass_through'])],
            'vendor_tax_evidence' => 'nullable|string|max:2000', 'tax_charge_description' => 'nullable|string|max:500',
            'customer_tax_treatment' => ['required', Rule::in(['unknown', 'none', 'exclusive'])],
            'customer_tax_rate' => $decimal, 'customer_tax_evidence' => 'nullable|string|max:2000',
            'optional_lines' => 'nullable|array|max:10', 'optional_lines.*' => 'array:description,amount',
            'optional_lines.*.description' => 'nullable|string|max:500', 'optional_lines.*.amount' => $decimal,
            'valid_until' => ['nullable', 'string', 'regex:/^\\d{4}-\\d{2}-\\d{2}(?:T\\d{2}:\\d{2})?$/D', 'date'], 'issue_date' => 'required|date_format:Y-m-d',
            'to_contact_id' => 'nullable|integer', 'cc_contact_ids' => 'nullable|array|max:10', 'cc_contact_ids.*' => 'integer|distinct',
            'subject' => 'nullable|string|max:300|not_regex:/[\r\n]/', 'body' => 'nullable|string|max:12000',
            'inclusions' => 'nullable|string|max:4000', 'exclusions' => 'nullable|string|max:4000', 'conditions' => 'nullable|string|max:12000',
            'terms_confirmed' => 'nullable|boolean', 'internal_notes' => 'nullable|string|max:4000',
            'resend_of_id' => 'nullable|integer|exists:mail_dispatches,id', 'resend_confirmed' => 'nullable|boolean',
        ];
    }
}
