<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorConfirmationRequest extends FormRequest
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
            'action_key' => 'required|uuid', 'expected_revision' => 'required|integer|min:0', 'status' => 'required|in:pending,confirmed,conditional,changed,unavailable',
            'channel' => 'required|in:email,phone,whatsapp,meeting,other', 'confirmed_at' => 'required|date', 'expires_at' => 'nullable|date',
            'contact_id' => 'nullable|integer', 'mail_message_id' => 'nullable|integer', 'identity_confirmed' => 'nullable|boolean',
            'rate_total' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,8})?$/D'],
            'currency' => 'nullable|string|size:3', 'rate_agreed' => 'nullable|boolean', 'scope_agreed' => 'nullable|boolean',
            'capacity_confirmed' => 'nullable|boolean', 'dates_agreed' => 'nullable|boolean', 'available_date' => 'nullable|date_format:Y-m-d',
            'arrival_date' => 'nullable|date_format:Y-m-d', 'conditions' => 'nullable|string|max:4000', 'notes' => 'required|string|max:8000',
            'document_ids' => 'nullable|array|max:20', 'document_ids.*' => 'integer|distinct'];
    }
}
