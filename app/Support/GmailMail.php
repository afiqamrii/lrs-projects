<?php

namespace App\Support;

use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GmailMail
{
    public function call(MailboxConnection $c, string $method, string $path, array $data = [], ?string $token = null): array
    {
        if ($c->provider !== 'gmail' || ! preg_match('#^/(profile|settings/sendAs|labels(?:/[^/?]+)?|history|messages(?:/[^/?]+(?:/attachments/[^/?]+)?)?|drafts(?:/[^/?]+)?)(?:\\?|$)#D', $path)) {
            throw new GmailFailure(400);
        }
        $fresh = $c->fresh();
        if ($fresh->generation !== $c->generation || $fresh->identity_hash !== $c->identity_hash || (! $token && ! $fresh->usable())) {
            throw new GmailFailure(401);
        }
        if ($c->is_demo) {
            return app(GmailFixture::class)->call($c, $method, $path, $data);
        }
        try {
            $http = Http::withToken($token ?? app(Mailboxes::class)->token($c))->acceptJson()->timeout(25)->connectTimeout(10)->withoutRedirecting();
            $r = $http->send($method, 'https://gmail.googleapis.com/gmail/v1/users/me'.$path, $method === 'GET' ? [] : ['json' => $data]);
        } catch (GraphFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GmailFailure(0, 30, $method !== 'GET');
        }
        if (strlen($r->body()) > 48 * 1024 * 1024) {
            throw new GmailFailure(413);
        }
        if (! $r->successful()) {
            $reason = $r->json('error.errors.0.reason');
            $quota = $r->status() === 429 || ($r->status() === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'], true));
            $retry = $r->header('Retry-After');
            $delay = is_numeric($retry) ? (int) $retry : ($retry ? max(1, strtotime($retry) - time()) : ($reason === 'dailyLimitExceeded' ? 3600 : 30));
            throw new GmailFailure($quota ? 429 : $r->status(), $delay, $method !== 'GET' && $r->status() >= 500);
        }
        $value = $r->json();
        if (! is_array($value)) {
            throw new GmailFailure(0, 30, $method !== 'GET');
        }

        return $value;
    }

    public function checkAlias(MailboxConnection $c): void
    {
        $aliases = $this->call($c, 'GET', '/settings/sendAs')['sendAs'] ?? [];
        $expected = mb_strtolower($c->from_alias ?: $c->account_email);
        $valid = collect($aliases)->contains(fn ($a) => mb_strtolower($a['sendAsEmail'] ?? '') === $expected && (($a['verificationStatus'] ?? '') === 'accepted' || (! empty($a['isPrimary']) && $expected === $c->account_email)));
        if (! $valid) {
            app(MailRelease::class)->fail('The approved Gmail From address is no longer a verified send-as identity. Reconnect or approve a new envelope; no alias is created automatically.');
        }
        DB::transaction(function () use ($c, $aliases): void {
            $locked = MailboxConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($locked->generation !== $c->generation || $locked->identity_hash !== $c->identity_hash || ! $locked->usable()) {
                throw new GmailFailure(401);
            }
            $locked->update(['aliases' => $aliases, 'aliases_checked_at' => now()]);
        });
    }

    public function sent(MailboxConnection $c, MailDispatch $d): array
    {
        $ids = $d->provider_sent_id ? [$d->provider_sent_id] : [];
        $internet = $d->internet_id ?: '<'.$d->dispatch_key.'@lrs.invalid>';
        $page = $this->call($c, 'GET', '/messages?'.http_build_query(['labelIds' => 'SENT', 'q' => 'rfc822msgid:'.trim($internet, '<>'), 'maxResults' => 20]));
        if (! empty($page['nextPageToken'])) {
            app(MailRelease::class)->fail('Multiple Gmail correlation pages require staff investigation. No resend.');
        }
        foreach ($page['messages'] ?? [] as $m) {
            $ids[] = $m['id'];
        }
        $found = [];
        foreach (array_unique($ids) as $id) {
            try {
                $m = $this->call($c, 'GET', '/messages/'.rawurlencode($id).'?format=full');
                if (! in_array('SENT', $m['labelIds'] ?? [], true) || in_array('DRAFT', $m['labelIds'] ?? [], true)) {
                    continue;
                }
                app(GmailMime::class)->verify($c, $m, $d->envelope->snapshot, $d, false);
                $found[$id] = $m;
            } catch (GmailFailure $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        if (count($found) > 1) {
            app(MailRelease::class)->fail('More than one matching SENT message exists. Review duplicate provider evidence; no resend.');
        }

        return $found;
    }

    public function draft(MailboxConnection $c, MailDispatch $d): ?array
    {
        $ids = $d->provider_draft_id ? [$d->provider_draft_id] : [];
        if (! $ids) {
            $page = $this->call($c, 'GET', '/drafts?'.http_build_query(['q' => 'rfc822msgid:'.$d->dispatch_key.'@lrs.invalid', 'maxResults' => 20, 'includeSpamTrash' => 'false']));
            if (! empty($page['nextPageToken'])) {
                app(MailRelease::class)->fail('Too many correlated Gmail drafts. Staff must investigate; no replacement or resend.');
            }
            foreach ($page['drafts'] ?? [] as $draft) {
                $ids[] = $draft['id'];
            }
        }
        $found = [];
        foreach (array_unique($ids) as $id) {
            try {
                $draft = $this->call($c, 'GET', '/drafts/'.rawurlencode($id).'?format=full');
                app(GmailMime::class)->verify($c, $draft['message'] ?? [], $d->envelope->snapshot, $d);
                $found[] = $draft;
            } catch (GmailFailure $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        if (count($found) > 1) {
            app(MailRelease::class)->fail('Multiple correlated Gmail draft containers require review; no draft will be sent.');
        }

        return $found[0] ?? null;
    }
}
