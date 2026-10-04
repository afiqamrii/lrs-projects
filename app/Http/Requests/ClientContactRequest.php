<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email)), 'is_primary' => $this->input('is_primary', 0), 'is_active' => $this->input('is_active', 0)]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('client_contacts')->where('client_id', $this->route('client')->id)->ignore($this->route('contact')?->id)], 'phone' => ['nullable', 'string', 'max:64'], 'role' => ['nullable', 'string', 'max:255'], 'is_active' => ['required', 'boolean'], 'is_primary' => ['required', 'boolean']];
    }
}
