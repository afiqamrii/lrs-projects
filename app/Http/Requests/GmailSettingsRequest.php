<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-company') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['account_email' => mb_strtolower(trim((string) $this->input('account_email'))), 'from_alias' => mb_strtolower(trim((string) $this->input('from_alias')))]);
    }

    public function rules(): array
    {
        return ['account_email' => ['required', 'email:rfc', 'max:254'], 'target_name' => ['required', 'string', 'max:120', 'not_regex:/[\\r\\n]/'], 'from_alias' => ['nullable', 'email:rfc', 'max:254'], 'import_from' => ['required', 'date_format:Y-m-d\\TH:i'], 'transport_limit_mb' => ['required', 'integer', 'between:1,33'], 'rights_confirmed' => ['accepted']];
    }
}
