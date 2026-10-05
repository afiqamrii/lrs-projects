<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandoffPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('required_documents', []);
        if (is_array($rows)) {
            $this->merge(['required_documents' => array_values(array_filter($rows, fn ($row) => ! is_array($row) || (is_string($row['label'] ?? null) ? trim($row['label']) !== '' : isset($row['label']))))]);
        }
    }

    public function rules(): array
    {
        return self::inputRules();
    }

    public static function inputRules(): array
    {
        return [
            'expected_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:2000', 'freshness_hours' => 'nullable|integer|min:1|max:8760',
            'require_po' => 'nullable|boolean', 'require_deposit' => 'nullable|boolean',
            'required_documents' => 'nullable|array|max:10', 'required_documents.*' => 'array:label,service',
            'required_documents.*.label' => 'required|string|max:150', 'required_documents.*.service' => 'required|in:any,pickup,delivery,clearance,insurance,storage,handling',
            'operational_exceptions' => 'nullable|array|max:3', 'operational_exceptions.*' => 'distinct|in:pickup_contact,delivery_contact,documents'];
    }
}
