<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vendor'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email)), 'is_primary' => $this->input('is_primary', false), 'is_active' => $this->input('is_active', true)]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'role' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:255', 'not_regex:/[\r\n]/', Rule::unique('contacts')->where('vendor_id', $this->route('vendor')->id)->ignore($this->route('contact')?->id)],
            'is_primary' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
        ];
    }
}
