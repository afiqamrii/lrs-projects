<?php

namespace App\Http\Requests;

use App\Models\Inquiry;
use App\Support\InquiryWorkflow;
use App\Support\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('inquiry') ? $this->user()->can('update', $this->route('inquiry')) : $this->user()->can('create', Inquiry::class);
    }

    protected function prepareForValidation(): void
    {
        $s = $this->input('shipment', []);
        $this->merge(['client_contact_id' => $this->input('client_contact_id')]);
        if (is_array($s)) {
            foreach (['services', 'special_flags', 'packages', 'containers'] as $key) {
                $s[$key] = $s[$key] ?? [];
            } $this->merge(['shipment' => $s]);
        }
    }

    public function rules(): array
    {
        $inquiry = $this->route('inquiry');
        $publicDraft = $inquiry?->source_channel === 'website';
        $rules = [
            'client_id' => [$publicDraft ? 'nullable' : 'required', 'integer', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('is_active', true)->when($inquiry, fn ($q) => $q->orWhere('id', $inquiry->client_id)))],
            'client_contact_id' => ['nullable', 'integer', Rule::exists('client_contacts', 'id')->where('client_id', $this->input('client_id'))],
            'title' => ['required', 'string', 'max:255'], 'owner_id' => [$publicDraft ? 'nullable' : 'required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::in(['normal', 'urgent'])], 'response_due_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'internal_notes' => ['nullable', 'string', 'max:12000'], 'shipment' => ['required', 'array'], 'add_row' => ['nullable', Rule::in(['packages', 'containers'])],
            'shipment.mode' => ['required', Rule::in(['unknown', 'LCL', 'FCL'])], 'shipment.scope' => ['required', Rule::in(array_keys(Shipment::SCOPES))],
            'shipment.services' => ['array', 'max:6'], 'shipment.services.*' => ['distinct', Rule::in(array_keys(Shipment::SERVICES))],
            'shipment.special_flags' => ['array', 'max:5'], 'shipment.special_flags.*' => ['distinct', Rule::in(array_keys(Shipment::SPECIAL))],
            'shipment.cargo_ready_date' => ['nullable', 'date_format:Y-m-d'], 'shipment.arrival_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('shipment.cargo_ready_date'), 'after_or_equal:shipment.cargo_ready_date')],
        ];
        foreach (Shipment::TEXT as $key) {
            if (! isset($rules['shipment.'.$key])) {
                $rules['shipment.'.$key] = ['nullable', 'string', 'max:4000'];
            }
        }
        foreach (['goods_currency', 'budget_currency', 'reference_currency'] as $key) {
            $rules['shipment.'.$key] = ['nullable', Rule::in(['MYR', 'USD', 'SGD', 'EUR', 'GBP', 'CNY', 'JPY', 'AUD', 'THB', 'IDR', 'HKD', 'INR'])];
        }
        foreach (Shipment::DECIMALS as $key) {
            $rules['shipment.'.$key] = ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/', 'numeric', 'gt:0'];
        }
        foreach (['goods_value' => 'goods_currency', 'budget' => 'budget_currency', 'reference_quote' => 'reference_currency'] as $amount => $currency) {
            $rules['shipment.'.$currency][] = 'required_with:shipment.'.$amount;
        }
        foreach (['packages', 'containers'] as $rows) {
            $rules['shipment.'.$rows] = ['array', 'max:20'];
            $rules['shipment.'.$rows.'.*'] = ['array'];
            $rules['shipment.'.$rows.'.*.quantity'] = ['nullable', 'integer', 'min:1', 'max:100000'];
            $rules['shipment.'.$rows.'.*.gross_weight'] = ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/', 'numeric', 'gt:0'];
            $rules['shipment.'.$rows.'.*.weight_unit'] = ['nullable', Rule::in(['kg', 't', 'lb']), 'required_with:shipment.'.$rows.'.*.gross_weight'];
        }
        $rules['shipment.packages.*.packaging_type'] = ['nullable', 'string', 'max:80'];
        foreach (['length', 'width', 'height'] as $key) {
            $rules['shipment.packages.*.'.$key] = ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/', 'numeric', 'gt:0'];
        }
        $rules['shipment.packages.*.dimension_unit'] = ['nullable', Rule::in(['mm', 'cm', 'm', 'in']), 'required_with:shipment.packages.*.length,shipment.packages.*.width,shipment.packages.*.height'];
        $rules['shipment.containers.*.type'] = ['nullable', Rule::in(Shipment::CONTAINERS)];
        if ($inquiry) {
            $rules['lock_version'] = ['required', 'integer', 'min:0'];
            foreach (['original_source_text', 'received_at', 'source_channel'] as $key) {
                $rules[$key] = ['prohibited'];
            }
        } else {
            $rules += ['received_at' => ['required', 'date_format:Y-m-d\TH:i'], 'source_channel' => ['required', Rule::in(array_diff(array_keys(Inquiry::CHANNELS), ['website']))], 'original_source_text' => ['nullable', 'string', 'max:30000']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $received = $this->route('inquiry')?->received_at ?? InquiryWorkflow::utc($this->input('received_at'));
            $due = InquiryWorkflow::utc($this->input('response_due_at'));
            if ($due && $due->lt($received)) {
                $validator->errors()->add('response_due_at', 'The response deadline cannot precede the received timestamp.');
            }
        }];
    }
}
