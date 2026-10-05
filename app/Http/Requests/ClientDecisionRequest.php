<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && in_array($this->user()->role, ['admin', 'agent'], true);
    }

    public function rules(): array
    {
        return self::inputRules();
    }

    public static function inputRules(): array
    {
        return [
            'action_key' => 'required|uuid', 'expected_decision' => 'required|integer|min:0', 'corrects_id' => 'nullable|integer',
            'outcome' => 'required|in:accepted,revision_requested,declined,question', 'channel' => 'required|in:email,phone,whatsapp,meeting,other',
            'decided_at' => 'required|date', 'contact_id' => 'nullable|integer', 'mail_message_id' => 'nullable|integer',
            'identity_confirmed' => 'nullable|boolean', 'scope_confirmed' => 'nullable|boolean', 'conditional' => 'nullable|boolean',
            'communicated_confirmed' => 'nullable|boolean', 'reference' => 'nullable|string|max:500', 'notes' => 'required|string|max:8000',
            'decline_reason' => 'nullable|in:price,timing,scope,cancelled,competitor,other', 'change_scope' => 'nullable|in:wording,shipment,cost,unknown',
            'po_document_id' => 'nullable|integer', 'document_ids' => 'nullable|array|max:20', 'document_ids.*' => 'integer|distinct'];
    }
}
