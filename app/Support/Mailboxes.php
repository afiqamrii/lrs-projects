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
use Illuminate\Validation\ValidationException;

class Mailboxes
{
    public function configure(array $data, ?MailboxConnection $connection = null): MailboxConnection
    {
        Gate::authorize('manage-company');

        return DB::transaction(function () use ($data, $connection): MailboxConnection {
            $c = MailboxConnection::whereKey($connection?->id ?? MailboxConnection::current()->id)->lockForUpdate()->firstOrFail();
            abort_unless($c->provider === 'outlook', 422);
            $c->fill($data);
            $c->forceFill(['tenant_id' => config('mailbox.tenant_id'), 'state' => 'disconnected', 'is_demo' => false, 'generation' => $c->generation + 1, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'refresh_lease' => null, 'refresh_until' => null, 'last_error' => null]);
            $c->identity_hash = $this->identity($c);
            $c->save();
            $this->event($c, 'Mailbox configuration changed; previous release authorizations invalidated.');

            return $c;
        });
    }

    public function identity(MailboxConnection $c): string
    {
        if ($c->provider === 'gmail') {
            return Processing::hash(['gmail', $c->id, $c->google_subject, $c->account_email, $c->from_alias, $c->target_name, $c->is_demo, $c->generation]);
        }

        return Processing::hash([$c->tenant_id, $c->account_id, $c->account_email, $c->target_id, $c->target_email, $c->target_name, $c->mailbox_type, $c->send_mode, $c->is_demo, $c->generation]);
    }

    public function scopes(MailboxConnection $c): string
    {
        return 'offline_access User.Read Mail.ReadWrite Mail.Send'.($c->mailbox_type === 'shared' ? ' User.ReadBasic.All Mail.ReadWrite.Shared Mail.Send.Shared' : '');
    }

    public function authorization(Request $request, ?MailboxConnection $connection = null): string
    {
        Gate::authorize('manage-company');
        $c = $connection ?? MailboxConnection::current();
        if (! config('mailbox.client_id') || ! config('mailbox.client_secret') || ! $c->tenant_id || ! $c->account_id || ! $c->target_id || ! $c->rights_confirmed) {
            throw ValidationException::withMessages(['connection' => 'Configure the Microsoft app, expected account, target and verified rights first.']);
        }
        $state = Str::random(64);
        $verifier = Str::random(96);
        DB::table('mail_oauth_attempts')->insert(['mailbox_connection_id' => $c->id, 'provider' => 'outlook', 'state_hash' => hash('sha256', $state), 'user_id' => $request->user()->id, 'session_hash' => hash('sha256', $request->session()->getId()), 'verifier' => Crypt::encryptString($verifier), 'generation' => $c->generation, 'expires_at' => now()->addMinutes(10)]);

        return 'https://login.microsoftonline.com/'.$c->tenant_id.'/oauth2/v2.0/authorize?'.http_build_query(['client_id' => config('mailbox.client_id'), 'response_type' => 'code', 'redirect_uri' => config('mailbox.redirect_uri'), 'response_mode' => 'query', 'scope' => $this->scopes($c), 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'login_hint' => $c->account_email]);
    }

    public function callback(Request $request): void
    {
        Gate::authorize('manage-company');
        $attempt = DB::transaction(function () use ($request): object {
            $a = DB::table('mail_oauth_attempts')->where('state_hash', hash('sha256', (string) $request->query('state')))->lockForUpdate()->first();
            if (! $a || $a->used_at || CarbonImmutable::parse($a->expires_at)->isPast() || $a->user_id !== $request->user()->id || ! hash_equals($a->session_hash, hash('sha256', $request->session()->getId()))) {
                throw ValidationException::withMessages(['connection' => 'The sign-in attempt is invalid, expired, already used or belongs to another session. Start again.']);
            }
            DB::table('mail_oauth_attempts')->where('state_hash', $a->state_hash)->update(['used_at' => now()]);

            return $a;
        });
        $c = MailboxConnection::findOrFail($attempt->mailbox_connection_id);
        if ($attempt->provider !== 'outlook' || $attempt->generation !== $c->generation || $request->query('error') || ! is_string($request->query('code'))) {
            throw ValidationException::withMessages(['connection' => 'Sign-in was cancelled or mailbox configuration changed. Start again.']);
        }
        $tokens = $this->exchange($c, ['grant_type' => 'authorization_code', 'code' => $request->query('code'), 'code_verifier' => Crypt::decryptString($attempt->verifier), 'redirect_uri' => config('mailbox.redirect_uri')]);
        $graph = app(GraphMail::class);
        $me = $graph->call($c, 'GET', '/me?$select=id,mail,userPrincipalName', token: $tokens['access_token']);
        $email = mb_strtolower($me['mail'] ?? $me['userPrincipalName'] ?? '');
        if (($me['id'] ?? null) !== $c->account_id || $email !== $c->account_email) {
            throw ValidationException::withMessages(['connection' => 'The signed-in account does not match the pinned company account.']);
        }
        $target = $c->target_id === $c->account_id ? $me : $graph->call($c, 'GET', '/users/'.$c->target_id.'?$select=id,mail,userPrincipalName', token: $tokens['access_token']);
        if (($target['id'] ?? null) !== $c->target_id || mb_strtolower($target['mail'] ?? $target['userPrincipalName'] ?? '') !== $c->target_email) {
            throw ValidationException::withMessages(['connection' => 'The target mailbox identity differs from its configured identity.']);
        }
        foreach (['inbox', 'drafts', 'sentitems'] as $name) {
            $graph->call($c, 'GET', '/users/'.$c->target_id.'/mailFolders/'.$name, token: $tokens['access_token']);
        }
        if ($c->account_sent_items && $c->account_id !== $c->target_id) {
            $graph->call($c, 'GET', '/users/'.$c->account_id.'/mailFolders/sentitems', token: $tokens['access_token']);
        }
        DB::transaction(function () use ($attempt, $tokens): void {
            $locked = MailboxConnection::whereKey($attempt->mailbox_connection_id)->lockForUpdate()->firstOrFail();
            if ($locked->generation !== $attempt->generation) {
                throw new GraphFailure(401);
            }
            $locked->update(['state' => 'connected', 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'expires_at' => now()->addSeconds($tokens['expires_in']), 'refresh_lease' => null, 'refresh_until' => null, 'last_error' => null]);
            $this->event($locked, 'Pinned account and target access verified. Exchange send rights still require the recorded Admin check.');
        });
        $this->defaultFolders($c->fresh());
    }

    private function exchange(MailboxConnection $c, array $data): array
    {
        try {
            $r = Http::asForm()->timeout(25)->connectTimeout(10)->withoutRedirecting()->post('https://login.microsoftonline.com/'.$c->tenant_id.'/oauth2/v2.0/token', $data + ['client_id' => config('mailbox.client_id'), 'client_secret' => config('mailbox.client_secret'), 'scope' => $this->scopes($c)]);
        } catch (\Throwable $e) {
            throw new GraphFailure(0);
        }
        if (! $r->successful()) {
            throw new GraphFailure(in_array($r->status(), [400, 401], true) ? 401 : $r->status());
        }
        $tokens = $r->json();
        $tokens['refresh_token'] = $tokens['refresh_token'] ?? $c->refresh_token;
        if (empty($tokens['access_token']) || empty($tokens['refresh_token']) || empty($tokens['expires_in'])) {
            throw new GraphFailure(401);
        }

        return $tokens;
    }

    public function token(MailboxConnection $connection): string
    {
        $lease = (string) Str::uuid();
        $c = DB::transaction(function () use ($lease, $connection): MailboxConnection {
            $c = MailboxConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $c->usable() || $c->identity_hash !== $connection->identity_hash || $c->generation !== $connection->generation) {
                throw new GraphFailure(401);
            }
            if ($c->expires_at?->gt(now()->addMinute()) && $c->access_token) {
                return $c;
            }
            if ($c->refresh_until?->isFuture()) {
                throw new GraphFailure(429, 3);
            }
            $c->update(['refresh_lease' => $lease, 'refresh_until' => now()->addSeconds(90)]);

            return $c;
        });
        if ($c->refresh_lease !== $lease) {
            return $c->access_token;
        }
        try {
            $tokens = $c->provider === 'gmail' ? app(GmailOAuth::class)->exchange($c, ['grant_type' => 'refresh_token', 'refresh_token' => $c->refresh_token]) : $this->exchange($c, ['grant_type' => 'refresh_token', 'refresh_token' => $c->refresh_token]);
        } catch (GraphFailure $e) {
            $changed = MailboxConnection::whereKey($c->id)->where('refresh_lease', $lease)->where('generation', $c->generation)->update(['refresh_lease' => null, 'refresh_until' => null] + ($e->status === 401 ? ['state' => 'paused', 'last_error' => $e->getMessage()] : []));
            if (! $changed) {
                throw new GraphFailure(429, 3);
            }
            throw $e;
        }

        return DB::transaction(function () use ($c, $tokens, $lease): string {
            $latest = MailboxConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($latest->generation !== $c->generation || ! $latest->usable()) {
                throw new GraphFailure(401);
            }
            if ($latest->refresh_lease !== $lease) {
                throw new GraphFailure(429, 3);
            }
            $latest->update(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'expires_at' => now()->addSeconds($tokens['expires_in']), 'refresh_lease' => null, 'refresh_until' => null]);

            return $latest->access_token;
        });
    }

    public function pause(string $reason, ?MailboxConnection $expected = null): void
    {
        DB::transaction(function () use ($reason, $expected): void {
            $current = MailboxConnection::whereKey($expected?->id ?? MailboxConnection::current()->id)->lockForUpdate()->firstOrFail();
            if ($expected && ($current->identity_hash !== $expected->identity_hash || $current->generation !== $expected->generation)) {
                return;
            } $current->update(['state' => 'paused', 'last_error' => $reason]);
        });
    }

    public function changeState(string $action, ?MailboxConnection $connection = null): void
    {
        Gate::authorize('manage-company');
        DB::transaction(function () use ($action, $connection): void {
            $c = MailboxConnection::whereKey($connection?->id ?? MailboxConnection::current()->id)->lockForUpdate()->firstOrFail();
            if ($action === 'resume' && $c->access_token) {
                $c->update(['state' => 'connected', 'last_error' => null]);
            } elseif ($action === 'pause') {
                $c->update(['state' => 'paused']);
            } elseif ($action === 'disconnect') {
                $c->update(['state' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'generation' => $c->generation + 1, 'refresh_lease' => null, 'refresh_until' => null]);
                $c->update(['identity_hash' => $this->identity($c)]);
            }
            $this->event($c, 'Mailbox '.$action.'. Historical evidence retained; in-flight submissions cannot be recalled.');
        });
    }

    public function defaultFolders(MailboxConnection $c): void
    {
        foreach ([[$c->target_id, 'inbox', 'incoming'], [$c->target_id, 'sentitems', 'sent'], ...($c->account_sent_items && $c->account_id !== $c->target_id ? [[$c->account_id, 'sentitems', 'sent']] : [])] as [$mailbox,$name,$kind]) {
            $folder = app(GraphMail::class)->call($c, 'GET', '/users/'.$mailbox.'/mailFolders/'.$name);
            MailboxFolder::firstOrCreate(['identity_hash' => $c->identity_hash, 'mailbox_id' => $mailbox, 'provider_id' => $folder['id']], ['mailbox_connection_id' => $c->id, 'name' => $folder['displayName'] ?? $name, 'kind' => $kind, 'import_from' => $c->import_from ?? now()]);
        }
    }

    public function demo(): void
    {
        Gate::authorize('manage-company');
        abort_unless(config('mailbox.demo_enabled') && app()->environment('local', 'testing'), 404);
        DB::transaction(function (): void {
            $c = MailboxConnection::whereKey(MailboxConnection::current()->id)->lockForUpdate()->firstOrFail();
            $c->fill(['is_demo' => true, 'state' => 'connected', 'tenant_id' => '11111111-1111-4111-8111-111111111111', 'account_id' => '22222222-2222-4222-8222-222222222222', 'target_id' => '22222222-2222-4222-8222-222222222222', 'account_email' => 'operations@example.test', 'target_email' => 'operations@example.test', 'target_name' => 'Synthetic LRS operations', 'mailbox_type' => 'personal', 'send_mode' => 'send_as', 'rights_confirmed' => true, 'transport_limit' => 25 * 1024 * 1024, 'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_at' => now()->addYear(), 'generation' => $c->generation + 1, 'import_from' => now()->subDay(), 'last_error' => null]);
            $c->identity_hash = $this->identity($c);
            $c->save();
            $this->event($c, 'Explicit local fixture connection. No Microsoft request or real message.');
        });
        $this->defaultFolders(MailboxConnection::current());
    }

    private function event(MailboxConnection $c, string $note): void
    {
        Audit::record('Mailbox connection changed', $c, details: ['connection' => ['before' => null, 'after' => $note]]);
    }
}
