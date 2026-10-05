<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FollowupPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' && $this->user()?->is_active;
    }

    protected function prepareForValidation(): void
    {
        $intervals = $this->input('interval_days', '2,3');
        $holidays = $this->input('holiday_dates') ?? '';
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'holidays' => is_string($holidays) ? array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $holidays)))) : null,
            'intervals' => is_string($intervals) ? array_values(array_filter(array_map('trim', explode(',', $intervals)), fn ($value) => $value !== '')) : null,
            'timezone' => CompanySetting::current()->timezone,
        ]);
    }

    public function rules(): array
    {
        return [
            'interval_days' => ['required', 'string', 'max:100'], 'holiday_dates' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::in(['rfq', 'client_quote'])], 'expected_number' => ['required', 'integer', 'min:0'], 'enabled' => ['boolean'], 'intervals' => ['required', 'array', 'min:1', 'max:5'], 'intervals.*' => ['integer', 'between:1,30'],
            'max_sends' => ['required', 'integer', 'between:1,5'], 'weekdays' => ['required', 'array', 'min:1', 'max:7'], 'weekdays.*' => ['integer', 'between:1,7', 'distinct'], 'holidays' => ['array', 'max:366'], 'holidays.*' => ['date_format:Y-m-d', 'distinct'],
            'opens' => ['required', 'date_format:H:i'], 'closes' => ['required', 'date_format:H:i', 'after:opens'], 'timezone' => ['required', 'timezone'], 'freshness_minutes' => ['required', 'integer', 'between:5,120'],
            'subject' => ['required', 'string', 'max:250', 'not_regex:/[\r\n]/'], 'body' => ['required', 'string', 'max:5000'], 'attachment_mode' => ['required', Rule::in(['none', 'approved'])], 'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'intervals.*.integer' => 'Business-day intervals must be whole days.',
            'intervals.*.between' => 'Each business-day interval must be between 1 and 30 days.',
            'weekdays.*.integer' => 'Choose valid working weekdays.',
            'weekdays.*.between' => 'Choose valid working weekdays.',
            'holidays.*.date_format' => 'Enter each company holiday as YYYY-MM-DD.',
            'closes.after' => 'The sending window must close after it opens on the same day.',
        ];
    }

    public function after(): array
    {
        return [function ($v): void {
            if (is_array($this->input('intervals')) && count($this->input('intervals')) !== (int) $this->input('max_sends')) {
                $v->errors()->add('interval_days', 'Provide exactly one business-day interval per permitted send.');
            }
            $subject = $this->input('subject');
            $body = $this->input('body');
            preg_match_all('/\[([^\]]+)\]/', (is_string($subject) ? $subject : '').' '.(is_string($body) ? $body : ''), $matches);
            if (array_diff($matches[1], ['original_subject', 'reference', 'revision', 'recipient_name', 'company_name', 'deadline', 'stage_number'])) {
                $v->errors()->add('body', 'Use only the listed approved placeholders.');
            }
        }];
    }
}
