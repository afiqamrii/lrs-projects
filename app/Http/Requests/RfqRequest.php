<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('rfq'));
    }

    public function rules(): array
    {
        return ['expected_revision' => ['required', 'integer', 'min:1'], 'to_contact_id' => ['required', 'integer'], 'cc_contact_ids' => ['nullable', 'array', 'max:20'], 'cc_contact_ids.*' => ['integer', 'distinct'], 'subject' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'], 'opening' => ['required', 'string', 'max:3000'], 'closing' => ['required', 'string', 'max:3000'], 'vendor_notes' => ['nullable', 'string', 'max:3000'], 'alternative_notes' => ['nullable', 'string', 'max:2000'], 'response_due_at' => ['nullable', 'date_format:Y-m-d\\TH:i'], 'currency' => ['required', Rule::in(['MYR', 'USD', 'SGD', 'EUR', 'GBP', 'CNY', 'JPY', 'AUD', 'THB', 'IDR'])], 'document_ids' => ['nullable', 'array', 'max:20'], 'document_ids.*' => ['integer', 'distinct'], 'attachments_reviewed' => ['nullable', 'boolean'], 'disclose_identity' => ['nullable', 'boolean'], 'disclose_addresses' => ['nullable', 'boolean'], 'refresh_company' => ['nullable', 'boolean'], 'disclosure_notes' => ['nullable', 'string', 'max:2000'], 'limitation_reason' => ['nullable', 'string', 'max:2000'], 'shared_email_reason' => ['nullable', 'string', 'max:2000'], 'deadline_reason' => ['nullable', 'string', 'max:2000'], 'change_reason' => ['nullable', 'string', 'max:2000']];
    }
}
