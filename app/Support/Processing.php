<?php

namespace App\Support;

use App\Models\CompanySetting;
use Illuminate\Validation\ValidationException;

class Processing
{
    public const DOCUMENT = [
        'queued' => 'Queued', 'processing' => 'Processing', 'extracted' => 'Extracted',
        'partial' => 'Partial', 'manual_review' => 'Needs manual review', 'failed' => 'Failed', 'unavailable' => 'Unavailable',
    ];

    public const AI = ['queued' => 'Queued', 'processing' => 'Processing', 'needs_review' => 'Needs review', 'failed' => 'Failed', 'unavailable' => 'Unavailable'];

    public const DOCUMENT_TRANSITIONS = [
        'queued' => ['processing', 'failed', 'unavailable'],
        'processing' => ['extracted', 'partial', 'manual_review', 'failed', 'unavailable'],
    ];

    public const AI_TRANSITIONS = [
        'queued' => ['processing', 'unavailable'],
        'processing' => ['needs_review', 'failed', 'unavailable'],
    ];

    public static function canTransition(string $kind, string $from, string $to): bool
    {
        $transitions = $kind === 'document' ? self::DOCUMENT_TRANSITIONS : self::AI_TRANSITIONS;

        return in_array($to, $transitions[$from] ?? [], true);
    }

    public const DOCUMENT_SUCCESS = ['extracted', 'partial', 'manual_review'];

    public static function hash(array $data): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['processing' => $message]);
    }

    public static function time(mixed $value): string
    {
        return $value?->setTimezone(CompanySetting::current()->timezone)->format('d M Y, H:i') ?? 'Not started';
    }
}
