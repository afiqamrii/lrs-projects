<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email)), 'is_active' => $this->input('is_active', false)]);
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($staff?->id), ...($staff ? [Rule::in([$staff->email])] : [])], 'role' => ['required', Rule::in(['admin', 'agent'])], 'is_active' => ['required', 'boolean']];
    }
}
