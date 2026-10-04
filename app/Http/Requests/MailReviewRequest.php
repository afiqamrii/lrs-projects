<?php

namespace App\Http\Requests;

use App\Models\MailMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'decision' => ['required', Rule::in(['associate', 'new', 'unmatched', 'ignore'])], 'classification' => ['required', Rule::in(array_keys(MailMessage::CLASSES))], 'inquiry_id' => ['required_if:decision,associate', 'nullable', 'integer', 'exists:inquiries,id'], 'rfq_revision_id' => ['nullable', 'integer', 'exists:rfq_revisions,id'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
