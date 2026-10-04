<?php

namespace App\Http\Requests;

use App\Models\Inquiry;
use App\Support\InquiryWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['note', 'client_response'])], 'channel' => ['required', Rule::in(array_diff(array_keys(Inquiry::CHANNELS), ['website']))], 'recipient' => ['nullable', 'string', 'max:255'], 'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'], 'notes' => ['required', 'string', 'max:12000'], 'document_ids' => ['nullable', 'array', 'max:20'], 'document_ids.*' => ['integer', 'distinct', Rule::exists('inquiry_documents', 'id')->where('inquiry_id', $this->route('inquiry')->id)]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && InquiryWorkflow::utc($this->input('occurred_at'))->gt(now())) {
                $validator->errors()->add('occurred_at', 'Record communication that has already occurred.');
            }
        }];
    }
}
