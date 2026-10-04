<?php

namespace App\Http\Requests;

use App\Models\CompanySetting;
use App\Models\InquiryDocument;
use App\Support\InquiryUploads;
use App\Support\PublicIntake;
use App\Support\Shipment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PublicInquiryRequest extends InquiryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $shipment = $this->input('shipment', []);
        if (is_array($shipment)) {
            foreach (['services', 'special_flags', 'packages', 'containers'] as $key) {
                $shipment[$key] ??= [];
            }
            $shipment['mode'] ??= 'unknown';
            $shipment['scope'] ??= 'unknown';
            $this->merge(['shipment' => $shipment]);
        }
        if (is_string($this->input('contact.email'))) {
            $this->attributes->set('submitted_email_text', $this->input('contact.email'));
            $this->merge(['contact' => array_replace((array) $this->input('contact'), ['email' => mb_strtolower(trim($this->input('contact.email')))])]);
        }
    }

    public function rules(): array
    {
        $key = PublicIntake::key($this);
        if ($key->inquiry_id) {
            return ['intake_token' => ['required', 'string']];
        }
        $rules = array_filter(parent::rules(), fn ($key) => str_starts_with($key, 'shipment.'), ARRAY_FILTER_USE_KEY);
        foreach (['cargo_description', 'origin_country', 'origin_location', 'destination_country', 'destination_location'] as $field) {
            $rules['shipment.'.$field] = ['required', 'string', 'max:4000'];
        }
        $rules += [
            'intake_token' => ['required', 'string', 'size:64'],
            'shipment' => ['required', 'array:'.implode(',', array_merge(Shipment::TEXT, Shipment::DECIMALS, ['mode', 'scope', 'services', 'special_flags', 'packages', 'containers']))],
            'shipment.packages.*' => ['array:packaging_type,quantity,gross_weight,weight_unit,length,width,height,dimension_unit'],
            'shipment.containers.*' => ['array:type,quantity,gross_weight,weight_unit'],
            'contact' => ['required', 'array:name,email,company,phone'],
            'contact.name' => ['required', 'string', 'max:120'],
            'contact.email' => ['required', 'email:rfc', 'max:254'],
            'contact.company' => ['nullable', 'string', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'additional_notes' => ['nullable', 'string', 'max:8000'],
            'privacy_acknowledged' => ['accepted'],
            'privacy_version' => ['required', Rule::in([CompanySetting::current()->public_privacy_version])],
            'website' => ['nullable', 'string', 'max:0'],
            'files' => ['nullable', 'array', 'max:'.PublicIntake::uploadLimit()],
            'files.*' => ['file', 'max:'.PublicIntake::uploadMaxKb()],
            'classification' => ['required', Rule::in(array_keys(InquiryDocument::CLASSES))],
        ];
        $rules['shipment.packages.*'] = ['array:packaging_type,quantity,gross_weight,weight_unit,length,width,height,dimension_unit'];
        $rules['shipment.containers.*'] = ['array:type,quantity,gross_weight,weight_unit'];
        foreach (['owner_id', 'client_id', 'client_contact_id', 'title', 'status', 'priority', 'response_due_at', 'internal_notes', 'verified', 'approved_by', 'received_at', 'source_channel', 'original_source_text'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        if ($this->routeIs('public-inquiries.draft')) {
            unset($rules['privacy_acknowledged']);
            foreach (['contact.name', 'contact.email', 'shipment.cargo_description', 'shipment.origin_country', 'shipment.origin_location', 'shipment.destination_country', 'shipment.destination_location'] as $field) {
                $rules[$field][0] = 'nullable';
            }
            $rules['add_row'] = ['required', Rule::in(['packages', 'containers'])];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (PublicIntake::key($this)->inquiry_id) {
                return;
            }
            if (! CompanySetting::current()->public_intake_enabled) {
                $validator->errors()->add('intake_token', 'Online inquiries are currently unavailable. Please contact the company.');
            }
            $size = 0;
            foreach ($this->file('files', []) as $index => $file) {
                if (! $file->isValid()) {
                    continue;
                }
                $size += $file->getSize();
                if (! in_array(strtolower($file->getClientOriginalExtension()), config('public-intake.allowed_extensions'), true) || ! InquiryUploads::type($file)) {
                    $validator->errors()->add('files.'.$index, 'Choose a supported PDF, JPEG/PNG, DOCX, XLSX or UTF-8 CSV. Active content and macro-enabled files are not accepted.');
                }
            }
            if ($size > PublicIntake::uploadTotalKb() * 1024) {
                $validator->errors()->add('files', 'The combined files exceed the '.(PublicIntake::uploadTotalKb() / 1024).' MB limit.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['contact.name' => 'contact name', 'contact.email' => 'email address', 'shipment.cargo_description' => 'cargo description', 'shipment.origin_country' => 'origin country', 'shipment.origin_location' => 'origin port or location', 'shipment.destination_country' => 'destination country', 'shipment.destination_location' => 'destination port or location', 'privacy_acknowledged' => 'privacy acknowledgment', 'privacy_version' => 'privacy notice version'];
    }
}
