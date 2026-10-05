<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandoffEventRequest extends FormRequest
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
            'action_key' => 'required|uuid', 'kind' => 'required|in:handed_to_operations,booking_requested,booking_confirmed',
            'corrects_id' => 'nullable|integer', 'occurred_at' => 'required|date', 'notes' => 'required|string|max:5000',
            'vendor_reference' => 'nullable|string|max:500', 'contact_id' => 'nullable|integer', 'mail_message_id' => 'nullable|integer',
            'pickup_date' => 'nullable|date_format:Y-m-d', 'arrival_date' => 'nullable|date_format:Y-m-d', 'scope_confirmed' => 'nullable|boolean',
            'document_ids' => 'nullable|array|max:20', 'document_ids.*' => 'integer|distinct'];
    }
}
