<?php

namespace App\Http\Requests;

use App\Support\Processing;
use Illuminate\Foundation\Http\FormRequest;

class ExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('inquiry'));
    }

    public function rules(): array
    {
        return [
            'document_ids' => ['required', 'array', 'min:1', 'max:5'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
            'pages' => ['nullable', 'string', 'max:200', 'regex:/^\s*\d+(\s*[,\-]\s*\d+)*\s*$/'],
            'ocr_pages' => ['nullable', 'string', 'max:200', 'regex:/^\s*\d+(\s*[,\-]\s*\d+)*\s*$/'],
            'reprocess' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000', 'required_if:reprocess,1'],
        ];
    }

    public function pages(string $key): array
    {
        $text = trim((string) $this->input($key));
        if ($text === '') {
            return [];
        }
        $pages = [];
        foreach (explode(',', $text) as $part) {
            $bounds = array_map('intval', explode('-', trim($part)));
            $first = $bounds[0];
            $last = $bounds[1] ?? $first;
            if ($first < 1 || $last < $first || $last - $first > config('extraction.pages') || $last > 10000) {
                Processing::fail('Use a valid page selection within the configured processing limit.');
            }
            $pages = [...$pages, ...range($first, $last)];
        }
        $pages = array_values(array_unique($pages));
        if (count($pages) > config('extraction.pages')) {
            Processing::fail('Select no more than '.config('extraction.pages').' pages per run.');
        }

        return $pages;
    }
}
