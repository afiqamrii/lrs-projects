<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    public function rules(): array
    {
        return [
            'rfq_reply_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'], 'rfq_reply_email' => ['nullable', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'], 'rfq_signature' => ['nullable', 'string', 'max:3000'],
            'workspace_data_mode' => ['sometimes', Rule::in(['real', 'samples'])],
            'display_name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone:all'], 'currency' => ['required', Rule::in(['MYR', 'USD', 'SGD', 'EUR', 'GBP', 'CNY', 'JPY', 'AUD', 'THB', 'IDR'])],
            'public_intake_enabled' => ['sometimes', 'boolean'], 'public_service_intro' => ['sometimes', 'required', 'string', 'max:2000'],
            'public_contact_email' => ['nullable', 'email:rfc', 'max:254'], 'public_contact_phone' => ['nullable', 'string', 'max:64'], 'public_contact_address' => ['nullable', 'string', 'max:2000'],
            'public_privacy_notice' => ['sometimes', 'required', 'string', 'max:6000'], 'public_privacy_version' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'public_intake_owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)], 'receipt_mail_enabled' => ['sometimes', 'boolean'], 'receipt_mail_body' => ['sometimes', 'required', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $settings = CompanySetting::current();
            if ($this->has('public_privacy_notice') && $this->input('public_privacy_notice') !== $settings->public_privacy_notice && $this->input('public_privacy_version') === $settings->public_privacy_version) {
                $validator->errors()->add('public_privacy_version', 'Change the notice version when editing its wording. Previous submissions keep the earlier notice.');
            }
        }];
    }
}
