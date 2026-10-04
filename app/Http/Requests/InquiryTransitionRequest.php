<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InquiryTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'target' => ['required', Rule::in(['draft', 'needs_review', 'needs_client_information', 'ready_for_sourcing', 'on_hold', 'closed', 'resume', 'reopen'])], 'reason' => ['nullable', 'required_if:target,on_hold,closed,reopen', 'string', 'max:4000']];
    }
}
