<?php

namespace App\Support;

use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicIntake
{
    public static function sessionHash(Request $request): string
    {
        if (! $request->session()->has('public_intake_nonce')) {
            $request->session()->put('public_intake_nonce', bin2hex(random_bytes(32)));
        }

        return hash_hmac('sha256', $request->session()->get('public_intake_nonce'), config('app.key'));
    }

    public static function issue(Request $request): string
    {
        $existing = $request->session()->get('public_intake_token');
        if ($existing) {
            $key = DB::table('public_intake_keys')->where('token_hash', hash('sha256', $existing))->first();
            if ($key && ! $key->inquiry_id && now()->lt($key->expires_at)) {
                return $existing;
            }
        }
        $token = bin2hex(random_bytes(32));
        DB::table('public_intake_keys')->insert(['token_hash' => hash('sha256', $token), 'session_hash' => self::sessionHash($request), 'inquiry_id' => null, 'expires_at' => now()->addHours(config('public-intake.form_expiry_hours')), 'created_at' => now()]);
        $request->session()->put('public_intake_token', $token);

        return $token;
    }

    public static function key(Request $request, bool $lock = false): object
    {
        $token = $request->input('intake_token');
        $key = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)
            ? DB::table('public_intake_keys')->where('token_hash', hash('sha256', $token))->when($lock, fn ($query) => $query->lockForUpdate())->first()
            : null;
        if (! $key || ! hash_equals($key->session_hash, self::sessionHash($request)) || (! $key->inquiry_id && now()->gte($key->expires_at))) {
            throw ValidationException::withMessages(['intake_token' => 'This form has expired. Open a fresh inquiry form and try again.']);
        }

        return $key;
    }

    public static function previewOnly(): bool
    {
        $host = strtolower((string) parse_url(config('public-intake.url'), PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '']) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    public static function transportAvailable(CompanySetting $settings): bool
    {
        if (! $settings->receipt_mail_enabled) {
            return false;
        }
        $mailer = config('public-intake.receipt_mailer');
        $driver = $mailer ? config('mail.mailers.'.$mailer.'.transport') : null;
        if (! in_array($driver, ['smtp', 'ses', 'postmark', 'resend', 'mailgun', 'sendmail'], true)) {
            return false;
        }
        if (app()->environment('testing')) {
            return true;
        }
        if (self::previewOnly()) {
            return config('public-intake.allow_local_capture') && app()->environment('local') && $driver === 'smtp' && in_array(config('mail.mailers.'.$mailer.'.host'), ['127.0.0.1', 'localhost', '::1'], true);
        }

        return filled(config('mail.from.address')) && ! str_ends_with(config('mail.from.address'), '@example.com');
    }

    public static function uploadLimit(): int
    {
        return min(5, config('inquiries.upload_batch_limit'), config('inquiries.document_limit'), (int) ini_get('max_file_uploads'));
    }

    private static function phpLimitKb(string $setting): int
    {
        $value = trim((string) ini_get($setting));
        if ((int) $value <= 0) {
            return PHP_INT_MAX;
        }
        $factor = match (strtoupper(substr($value, -1))) {
            'G' => 1048576, 'M' => 1024, 'K' => 1, default => 1 / 1024
        };

        return (int) ((float) $value * $factor);
    }

    public static function uploadTotalKb(): int
    {
        return max(1, min(config('public-intake.upload_total_kb'), self::phpLimitKb('post_max_size') - 1024));
    }

    public static function uploadMaxKb(): int
    {
        return min(10240, config('inquiries.upload_max_kb'), self::phpLimitKb('upload_max_filesize'), self::uploadTotalKb());
    }
}
