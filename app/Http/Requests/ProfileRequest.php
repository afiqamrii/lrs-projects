<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'password' => ['nullable', 'confirmed', Password::defaults()], 'current_password' => ['required_with:password', 'nullable', 'current_password']];
    }
}
