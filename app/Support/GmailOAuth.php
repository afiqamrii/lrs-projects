<?php

namespace App\Support;

use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GmailOAuth
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.compose'];

    public function authorization(Request $request, MailboxConnection $c): string
    {
        Gate::authorize('manage-company');
        if ($c->provider !== 'gmail' || ! $c->rights_confirmed || ! config('mailbox.google_client_id') || ! config('mailbox.google_client_secret')) {
            Processing::fail('Configure the Google Web client and the company Gmail account first.');
        }
        $state = Str::random(64);
        $verifier = Str::random(96);
        DB::table('mail_oauth_attempts')->insert(['state_hash' => hash('sha256', $state), 'user_id' => $request->user()->id, 'session_hash' => hash('sha256', $request->session()->getId()), 'verifier' => Crypt::encryptString($verifier), 'generation' => $c->generation, 'mailbox_connection_id' => $c->id, 'provider' => 'gmail', 'expires_at' => now()->addMinutes(10)]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => config('mailbox.google_client_id'), 'redirect_uri' => config('mailbox.google_redirect_uri'), 'response_type' => 'code', 'scope' => implode(' ', self::SCOPES), 'state' => $state, 'code_challenge' => GmailMime::encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $c->account_email]);
    }

    public function callback(Request $request): MailboxConnection
    {
        Gate::authorize('manage-company');
        $a = DB::transaction(function () use ($request): object {
            $a = DB::table('mail_oauth_attempts')->where('state_hash', hash('sha256', (string) $request->query('state')))->lockForUpdate()->first();
            if (! $a || $a->provider !== 'gmail' || $a->used_at || CarbonImmutable::parse($a->expires_at)->isPast() || $a->user_id !== $request->user()->id || ! hash_equals($a->session_hash, hash('sha256', $request->session()->getId()))) {
                Processing::fail('This Gmail sign-in attempt is invalid, expired, already used or belongs to another session.');
            }
            DB::table('mail_oauth_attempts')->where('state_hash', $a->state_hash)->update(['used_at' => now()]);

            return $a;
        });
        $c = MailboxConnection::findOrFail($a->mailbox_connection_id);
        if ($c->provider !== 'gmail' || $c->generation !== $a->generation || $request->query('error') || ! is_string($request->query('code'))) {
            Processing::fail('Gmail sign-in was cancelled or the mailbox configuration changed. Start again.');
        }
        $tokens = $this->exchange($c, ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'code_verifier' => Crypt::decryptString($a->verifier), 'redirect_uri' => config('mailbox.google_redirect_uri')]);
        $this->permissions($tokens['scope'] ?? '');
        try {
            $r = Http::withToken($tokens['access_token'])->timeout(25)->connectTimeout(10)->withoutRedirecting()->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (\Throwable $e) {
            throw new GmailFailure(0);
        }
        if (! $r->successful()) {
            throw new GmailFailure($r->status());
        }
        $me = $r->json();
        $gmail = app(GmailMail::class);
        $profile = $gmail->call($c, 'GET', '/profile', token: $tokens['access_token']);
        $email = mb_strtolower($me['email'] ?? '');
        if (($me['email_verified'] ?? false) !== true || ! is_string($me['sub'] ?? null) || strlen($me['sub']) > 255 || $email !== $c->account_email || mb_strtolower($profile['emailAddress'] ?? '') !== $email || ($c->google_subject && $c->google_subject !== $me['sub'])) {
            Processing::fail('The authenticated Google account does not match the configured company mailbox. A login hint or delegated Gmail screen is insufficient.');
        }
        $aliases = $gmail->call($c, 'GET', '/settings/sendAs', token: $tokens['access_token'])['sendAs'] ?? [];
        DB::transaction(function () use ($a, $tokens, $me, $aliases): void {
            $locked = MailboxConnection::whereKey($a->mailbox_connection_id)->lockForUpdate()->firstOrFail();
            if ($locked->generation !== $a->generation) {
                throw new GmailFailure(401);
            }
            $locked->fill(['tenant_id' => 'google', 'google_subject' => $me['sub'], 'account_id' => $me['sub'], 'target_id' => $me['sub'], 'target_email' => $locked->account_email, 'from_alias' => $locked->from_alias ?: $locked->account_email, 'state' => 'connected', 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'expires_at' => now()->addSeconds($tokens['expires_in']), 'aliases' => $aliases, 'aliases_checked_at' => now(), 'granted_scopes' => preg_split('/\\s+/', $tokens['scope']), 'refresh_lease' => null, 'refresh_until' => null, 'last_error' => null]);
            $locked->identity_hash = app(Mailboxes::class)->identity($locked);
            $locked->save();
            Audit::record('Gmail account verified and connected', $locked, details: ['connection' => ['before' => null, 'after' => 'Self-account access verified. No sending or reminder activation.']]);
        });
        $c = $c->fresh();
        $this->folders($c);

        return $c;
    }

    public function exchange(MailboxConnection $c, array $data): array
    {
        if (empty($data['refresh_token']) && ($data['grant_type'] ?? '') === 'refresh_token') {
            throw new GmailFailure(401);
        }
        try {
            $r = Http::asForm()->timeout(25)->connectTimeout(10)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', $data + ['client_id' => config('mailbox.google_client_id'), 'client_secret' => config('mailbox.google_client_secret')]);
        } catch (\Throwable $e) {
            throw new GmailFailure(0);
        }
        if (! $r->successful()) {
            throw new GmailFailure(in_array($r->status(), [400, 401], true) ? 401 : $r->status());
        }
        $tokens = $r->json();
        $tokens['refresh_token'] = $tokens['refresh_token'] ?? $c->refresh_token;
        if (empty($tokens['access_token']) || empty($tokens['refresh_token']) || ! is_numeric($tokens['expires_in'] ?? null) || $tokens['expires_in'] <= 0) {
            throw new GmailFailure(401);
        }
        if (isset($tokens['scope'])) {
            $this->permissions($tokens['scope']);
        }

        return $tokens;
    }

    private function permissions(string $scope): void
    {
        $scopes = preg_split('/\\s+/', trim($scope));
        foreach (array_slice(self::SCOPES, 2) as $required) {
            if (! in_array($required, $scopes, true)) {
                throw new GmailFailure(403);
            }
        }
    }

    public function folders(MailboxConnection $c): void
    {
        MailboxFolder::firstOrCreate(['identity_hash' => $c->identity_hash, 'mailbox_id' => $c->target_id, 'provider_id' => 'INBOX'], ['mailbox_connection_id' => $c->id, 'name' => 'Inbox', 'kind' => 'incoming', 'import_from' => $c->import_from ?? now()]);
    }
}
