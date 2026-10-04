<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('vendor') ? $this->user()->can('update', $this->route('vendor')) : $this->user()->can('create', Vendor::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_email')) {
            $this->merge(['contact_email' => mb_strtolower(trim((string) $this->contact_email)) ?: null]);
        }
        $this->merge(['services' => $this->input('services', [])]);
    }

    public function rules(): array
    {
        $rules = [
            'company_name' => ['required', 'string', 'max:255'], 'display_name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Vendor::TYPES))], 'services' => ['array', 'max:6'],
            'services.*' => ['string', 'distinct', Rule::in(Vendor::SERVICES)],
            'coverage' => ['nullable', 'string', 'max:4000'], 'minimum_notes' => ['nullable', 'string', 'max:4000'],
            'communication_channel' => ['required', Rule::in(array_keys(Vendor::CHANNELS))], 'internal_notes' => ['nullable', 'string', 'max:8000'],
        ];
        if (! $this->route('vendor')) {
            $rules += ['contact_name' => ['nullable', 'required_with:contact_email', 'string', 'max:255'], 'contact_email' => ['nullable', 'required_with:contact_name,contact_role,contact_phone', 'email:rfc', 'max:255'], 'contact_role' => ['nullable', 'string', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:64']];
        }

        return $rules;
    }
}
