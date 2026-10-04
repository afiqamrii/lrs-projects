<?php

namespace App\Http\Requests;

use App\Models\InquiryDocument;
use App\Support\InquiryUploads;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return ['prepared_from_id' => ['nullable', 'integer', Rule::exists('inquiry_documents', 'id')->where('inquiry_id', $this->route('inquiry')->id)], 'prepared_note' => ['nullable', 'string', 'max:2000', 'required_with:prepared_from_id'], 'classification' => ['required', Rule::in(array_keys(InquiryDocument::CLASSES))], 'files' => ['required', 'array', 'min:1', 'max:'.config('inquiries.upload_batch_limit')], 'files.*' => ['required', 'file', 'max:'.config('inquiries.upload_max_kb')]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            } foreach ($this->file('files', []) as $index => $file) {
                if (! InquiryUploads::type($file)) {
                    $validator->errors()->add('files.'.$index, 'Choose an actual PDF, JPEG, PNG, DOCX, XLSX or CSV file. Its content must match its extension.');
                }
            }
        }];
    }
}
