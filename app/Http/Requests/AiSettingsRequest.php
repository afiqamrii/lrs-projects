<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    public function rules(): array
    {
        $rules = ['enabled' => ['required', 'boolean'], 'model' => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9._:\-]+$/'], 'structured_verified' => ['required', 'boolean'], 'rate_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'rate_version' => ['nullable', 'string', 'max:100'], 'rate_source' => ['nullable', 'url:https', 'max:500', 'starts_with:https://developers.openai.com/,https://platform.openai.com/,https://openai.com/']];
        foreach (['input_rate', 'cached_rate', 'output_rate', 'run_cap', 'inquiry_cap', 'daily_cap'] as $field) {
            $rules[$field] = ['nullable', 'regex:/^\d{1,6}(\.\d{1,6})?$/', 'numeric', 'min:0'];
        }

        return $rules;
    }
}
