<?php

namespace App\Support;

use App\Models\CompanySetting;

class WorkspaceData
{
    public const SAMPLE_SET = 'professional-preview-1';

    public static function preview(): bool
    {
        return CompanySetting::current()->workspace_data_mode === 'samples';
    }

    public static function exampleEmail(?string $email): bool
    {
        $domain = mb_strtolower(explode('@', $email ?? '')[1] ?? '');

        return str_ends_with($domain, '.example') || str_ends_with($domain, '.test') || in_array($domain, ['example.com', 'example.net', 'example.org'], true);
    }
}
