<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('client') ? $this->user()->can('update', $this->route('client')) : $this->user()->can('create', Client::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('contact_email')) {
            $this->merge(['contact_email' => mb_strtolower(trim((string) $this->contact_email)) ?: null]);
        }
    }

    public function rules(): array
    {
        $rules = ['company_name' => ['required', 'string', 'max:255'], 'reference_identifier' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:4000'], 'internal_notes' => ['nullable', 'string', 'max:8000']];
        if (! $this->route('client')) {
            $rules += ['contact_name' => ['nullable', 'required_with:contact_email', 'string', 'max:255'], 'contact_email' => ['nullable', 'required_with:contact_name', 'email:rfc', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:64'], 'contact_role' => ['nullable', 'string', 'max:255']];
        }

        return $rules;
    }
}
