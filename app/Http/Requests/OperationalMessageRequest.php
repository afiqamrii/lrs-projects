<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationalMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    public function rules(): array
    {
        return self::inputRules();
    }

    public static function inputRules(): array
    {
        return [
            'expected_revision' => 'required|integer|min:0', 'to_contact_id' => 'required|integer', 'cc_contact_ids' => 'nullable|array|max:10',
            'cc_contact_ids.*' => 'integer|distinct', 'subject' => 'required|string|max:300|not_regex:/[\\r\\n]/', 'body' => 'required|string|max:12000',
            'document_ids' => 'nullable|array|max:10', 'document_ids.*' => 'integer|distinct', 'disclosure_confirmed' => 'nullable|boolean',
            'reason' => 'required|string|max:2000', 'resend_of_id' => 'nullable|integer', 'resend_confirmed' => 'nullable|boolean'];
    }
}
