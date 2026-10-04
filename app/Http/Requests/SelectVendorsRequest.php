<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectVendorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'vendor_ids' => ['required', 'array', 'min:1', 'max:30'], 'vendor_ids.*' => ['required', 'integer', 'distinct']];
    }
}
