<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailboxSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-company') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['account_email' => mb_strtolower(trim((string) $this->input('account_email'))), 'target_email' => mb_strtolower(trim((string) $this->input('target_email')))]);
    }

    public function rules(): array
    {
        return ['account_id' => ['required', 'uuid'], 'account_email' => ['required', 'email:rfc', 'max:254'], 'target_id' => ['required', 'uuid'], 'target_email' => ['required', 'email:rfc', 'max:254'], 'target_name' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/'], 'mailbox_type' => ['required', Rule::in(['personal', 'shared'])], 'send_mode' => ['required', Rule::in(['send_as', 'on_behalf'])], 'import_from' => ['required', 'date_format:Y-m-d\TH:i'], 'transport_limit_mb' => ['required', 'integer', 'min:1', 'max:100'], 'rights_confirmed' => ['accepted'], 'account_sent_items' => ['nullable', 'boolean']];
    }
}
