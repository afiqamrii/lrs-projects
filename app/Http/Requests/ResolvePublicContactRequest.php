<?php

namespace App\Http\Requests;

use App\Models\MailMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePublicContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry')) && ($this->route('inquiry')->source_channel === 'website' || MailMessage::where('inquiry_id', $this->route('inquiry')->id)->where('direction', 'incoming')->exists());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact.email'))) {
            $this->merge(['contact' => array_replace((array) $this->input('contact'), ['email' => mb_strtolower(trim($this->input('contact.email')))])]);
        }
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'resolution' => ['required', Rule::in(['defer', 'existing', 'new_contact', 'new_client'])],
            'client_id' => ['required_if:resolution,existing,new_contact', 'nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'client_contact_id' => ['required_if:resolution,existing', 'nullable', 'integer', Rule::exists('client_contacts', 'id')->where('client_id', $this->input('client_id'))->where('is_active', true)],
            'contact' => ['required', 'array:name,email,company,phone'],
            'contact.name' => ['required', 'string', 'max:120'],
            'contact.email' => ['required', 'email:rfc', 'max:254'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'contact.company' => ['required_if:resolution,new_client', 'nullable', 'string', 'max:255'],
            'identity_assessed' => [Rule::when($this->input('resolution') !== 'defer', ['accepted'], ['nullable', 'boolean'])],
        ];
    }
}
