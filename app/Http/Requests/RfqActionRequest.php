<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RfqActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('rfq'));
    }

    public function rules(): array
    {
        $base = ['expected_revision' => ['required', 'integer', 'min:1']];
        if ($this->routeIs('rfqs.approve')) {
            return $base + ['digest' => ['required', 'string', 'size:64'], 'approve_exact' => ['accepted']];
        }
        if ($this->routeIs('rfqs.manual')) {
            return $base + ['digest' => ['required', 'string', 'size:64'], 'action_key' => ['required', 'uuid'], 'confirm_exact' => ['accepted'], 'sent_at' => ['required', 'date_format:Y-m-d\\TH:i'], 'channel' => ['required', 'in:email,other'], 'recipient' => ['required', 'email:rfc', 'not_regex:/[\r\n]/'], 'evidence' => ['nullable', 'string', 'max:4000']];
        }

        return $base + ['target' => ['required', 'in:needs_approval,changes_requested,cancelled'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
