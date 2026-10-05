<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandoffRequest extends FormRequest
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
            'expected_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:2000', 'operations_owner_id' => 'nullable|integer',
            'pickup_contact' => 'nullable|string|max:1000', 'delivery_contact' => 'nullable|string|max:1000',
            'cargo_ready_confirmed' => 'nullable|boolean', 'cargo_evidence' => 'nullable|string|max:3000',
            'deposit_confirmed' => 'nullable|boolean', 'deposit_evidence' => 'nullable|string|max:3000', 'deposit_at' => 'nullable|date',
            'document_ids' => 'nullable|array|max:20', 'document_ids.*' => 'integer|distinct',
            'document_map' => 'nullable|array|max:10', 'document_map.*' => 'nullable|integer', 'documents_evidence' => 'nullable|string|max:4000', 'exceptions' => 'nullable|array:pickup_contact,delivery_contact,documents',
            'exceptions.*' => 'nullable|string|max:2000'];
    }
}
