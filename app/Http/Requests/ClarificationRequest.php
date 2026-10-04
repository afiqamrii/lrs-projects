<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClarificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'client_contact_id' => ['required', 'integer', Rule::exists('client_contacts', 'id')->where('client_id', $this->route('inquiry')->client_id)->where('is_active', true)], 'body' => ['required', 'string', 'max:12000']];
    }
}
