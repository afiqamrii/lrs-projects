<?php

namespace App\Support;

use App\Models\FollowupPlan;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GmailFixture
{
    private function allowed(MailboxConnection $c): void
    {
        abort_unless($c->provider === 'gmail' && $c->is_demo && config('mailbox.demo_enabled') && app()->environment('local', 'testing'), 403);
    }

    public function connect(MailboxConnection $c): void
    {
        Gate::authorize('manage-company');
        abort_unless($c->provider === 'gmail' && config('mailbox.demo_enabled') && app()->environment('local', 'testing'), 404);
        $c->fill(['state' => 'connected', 'is_demo' => true, 'google_subject' => 'fictional-google-account-'.$c->id, 'tenant_id' => 'google', 'account_id' => 'fictional-google-account-'.$c->id, 'target_id' => 'fictional-google-account-'.$c->id, 'account_email' => 'operations.gmail@meridian-logistics.example', 'target_email' => 'operations.gmail@meridian-logistics.example', 'from_alias' => 'operations.gmail@meridian-logistics.example', 'target_name' => 'Meridian Logistics · Fictional Gmail', 'rights_confirmed' => true, 'transport_limit' => 33000000, 'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_at' => now()->addYear(), 'import_from' => now()->subDay(), 'generation' => $c->generation + 1, 'last_error' => null, 'aliases' => [['sendAsEmail' => 'operations.gmail@meridian-logistics.example', 'verificationStatus' => 'accepted', 'isPrimary' => true]], 'aliases_checked_at' => now()]);
        $c->identity_hash = app(Mailboxes::class)->identity($c);
        $c->save();
        app(GmailOAuth::class)->folders($c);
        Audit::record('Fictional Gmail connected', $c, details: ['connection' => ['before' => null, 'after' => 'Local fixture only. No Google request or real mail.']]);
    }

    public function state(MailboxConnection $c, ?array $state = null): array
    {
        $this->allowed($c);
        $path = 'gmail-fixture-'.$c->id.'.enc';
        if ($state !== null) {
            Storage::disk('mailbox')->put($path, Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)));
        }

        return Storage::disk('mailbox')->exists($path) ? json_decode(Crypt::decryptString(Storage::disk('mailbox')->get($path)), true, flags: JSON_THROW_ON_ERROR) : ['drafts' => [], 'messages' => [], 'history' => [], 'sequence' => 0, 'fault' => null];
    }

    public function call(MailboxConnection $c, string $method, string $path, array $data): array
    {
        $this->allowed($c);

        return Cache::lock('gmail-fixture-'.$c->id, 60)->block(10, fn () => $this->perform($c, $method, $path, $data));
    }

    private function perform(MailboxConnection $c, string $method, string $path, array $data): array
    {
        $state = $this->state($c);
        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $q);
        $path = parse_url($path, PHP_URL_PATH);
        $history = 'fixture-history-'.$state['sequence'];
        if ($state['fault'] && str_contains($path, $state['fault']['path'])) {
            $fault = $state['fault'];
            if (! empty($fault['once'])) {
                $state['fault'] = null;
                $this->state($c, $state);
            }
            throw new GmailFailure($fault['status'], $fault['retry'] ?? 30, $fault['ambiguous'] ?? false);
        }
        if ($path === '/profile') {
            return ['emailAddress' => $c->account_email, 'historyId' => $history];
        }
        if ($path === '/settings/sendAs') {
            return ['sendAs' => $state['aliases'] ?? [['sendAsEmail' => $c->account_email, 'verificationStatus' => 'accepted', 'isPrimary' => true]]];
        }
        if (preg_match('#^/labels/([^/]+)$#', $path, $m)) {
            return ['id' => $m[1], 'name' => $m[1], 'type' => $m[1] === 'INBOX' ? 'system' : 'user'];
        }
        if ($path === '/drafts' && $method === 'GET') {
            $drafts = array_values(array_filter($state['drafts'], fn ($d) => trim(GmailMime::headers($d['message'])['message-id'] ?? '', '<>') === substr($q['q'] ?? '', strlen('rfc822msgid:'))));

            return ['drafts' => array_map(fn ($d) => ['id' => $d['id'], 'message' => ['id' => $d['message']['id'], 'threadId' => $d['message']['threadId']]], array_slice($drafts, 0, 20))] + (count($drafts) > 20 ? ['nextPageToken' => 'review-limit'] : []);
        }
        if ($path === '/drafts' && $method === 'POST') {
            $key = (string) Str::uuid();
            $message = ['id' => 'draft-message-'.$key, 'threadId' => $data['message']['threadId'] ?? 'thread-'.$key, 'labelIds' => ['DRAFT'], 'payload' => self::payload(GmailMime::decode($data['message']['raw'])), 'internalDate' => (string) now()->getTimestampMs()];
            $draft = ['id' => 'draft-'.$key, 'message' => $message];
            $state['drafts'][$draft['id']] = $draft;
            $this->state($c, $state);

            return $draft;
        }
        if ($path === '/drafts/send' && $method === 'POST') {
            $draft = $state['drafts'][$data['id']] ?? null;
            if (! $draft) {
                throw new GmailFailure(404);
            }
            $message = $draft['message'];
            $message['id'] = 'sent-'.Str::uuid();
            $message['labelIds'] = ['SENT'];
            $state['messages'][$message['id']] = $message;
            unset($state['drafts'][$data['id']]);
            $this->state($c, $state);

            return ['id' => $message['id'], 'threadId' => $message['threadId'], 'labelIds' => ['SENT']];
        }
        if (preg_match('#^/drafts/([^/]+)$#', $path, $m)) {
            return $state['drafts'][rawurldecode($m[1])] ?? throw new GmailFailure(404);
        }
        if ($path === '/messages') {
            $items = array_filter($state['messages'], function ($m) use ($q): bool {
                if (! in_array($q['labelIds'] ?? 'INBOX', $m['labelIds'], true)) {
                    return false;
                }
                if (str_starts_with($q['q'] ?? '', 'rfc822msgid:')) {
                    return trim(GmailMime::headers($m)['message-id'] ?? '', '<>') === substr($q['q'], strlen('rfc822msgid:'));
                }

                return true;
            });
            $start = (int) ($q['pageToken'] ?? 0);
            $items = array_values($items);
            $page = array_slice($items, $start, 25);

            return ['messages' => array_map(fn ($m) => ['id' => $m['id'], 'threadId' => $m['threadId']], $page)] + ($start + count($page) < count($items) ? ['nextPageToken' => (string) ($start + count($page))] : []);
        }
        if (preg_match('#^/messages/([^/]+)(?:/attachments/([^/]+))?$#', $path, $m)) {
            $message = $state['messages'][rawurldecode($m[1])] ?? null;
            if (! $message) {
                foreach ($state['drafts'] as $d) {
                    if ($d['message']['id'] === $m[1]) {
                        $message = $d['message'];
                    }
                }
            }
            if (! $message) {
                throw new GmailFailure(404);
            }
            if (isset($m[2])) {
                return $state['attachments'][$m[2]] ?? throw new GmailFailure(404);
            }

            return $message;
        }
        if ($path === '/history') {
            $startId = $q['startHistoryId'] ?? '';
            if (! preg_match('/^fixture-history-(\\d+)$/D', $startId, $match)) {
                throw new GmailFailure(404);
            }
            $items = array_values(array_filter($state['history'], fn ($h) => $h['_sequence'] > (int) $match[1]));
            $start = (int) ($q['pageToken'] ?? 0);
            $page = array_slice($items, $start, 25);

            return ['history' => $page, 'historyId' => $history] + ($start + count($page) < count($items) ? ['nextPageToken' => (string) ($start + count($page))] : []);
        }
        throw new GmailFailure(400);
    }

    public function incoming(MailboxConnection $c, ?int $planId = null, ?MailDispatch $lifecycleDispatch = null, ?string $replyText = null): void
    {
        $this->allowed($c);
        Gate::authorize('manage-company');
        Cache::lock('gmail-fixture-'.$c->id, 60)->block(10, function () use ($c, $planId, $lifecycleDispatch, $replyText): void {
            $state = $this->state($c);
            $dispatch = $lifecycleDispatch;
            if ($dispatch) {
                abort_unless($dispatch->is_demo && $dispatch->envelope->mailbox_connection_id === $c->id && $dispatch->envelope->identity_hash === $c->identity_hash && in_array($dispatch->status, ['accepted', 'observed'], true), 422);
            }
            if ($planId) {
                $plan = FollowupPlan::findOrFail($planId);
                $stage = $plan->stages()->whereHas('envelope.dispatches', fn ($q) => $q->whereIn('status', ['accepted', 'observed']))->latest('id')->firstOrFail();
                $dispatch = $stage->envelope->dispatches()->latest('id')->firstOrFail();
                abort_unless($dispatch->envelope->mailbox_connection_id === $c->id && $dispatch->envelope->identity_hash === $c->identity_hash, 422);
            }
            $id = $replyText && $dispatch ? 'fictional-lifecycle-'.$dispatch->dispatch_key.'-'.substr(hash('sha256', $replyText), 0, 10) : ($dispatch ? 'fictional-question-'.$dispatch->dispatch_key : 'fictional-inquiry-'.$c->id);
            if (isset($state['messages'][$id])) {
                return;
            }
            $from = $dispatch ? $dispatch->envelope->snapshot['content']['to'] : ['name' => 'Nadia Rahman', 'email' => 'nadia.rahman@straits-components.example'];
            $subject = $dispatch ? 'Re: '.$dispatch->envelope->snapshot['content']['subject'] : 'Port Klang to Singapore · machinery parts, 3 pallets';
            $text = $dispatch ? "Thank you for the quotation. Could you confirm the earliest departure and the delivery arrangement?\n\nRegards,\n".$from['name']."\n[Fictional business preview]" : "Hello Meridian Logistics,\n\nWe need an LCL quotation from Port Klang to Singapore for three pallets of machinery parts, 1,250 kg and 4.8 CBM. Cargo will be ready on 14 October. Please include ocean freight and delivery to our Jurong warehouse, and advise transit time.\n\nRegards,\nNadia Rahman\nStraits Components\n[Fictional business preview]";
            if ($replyText) {
                $text = $replyText."\n\n[Fictional business preview — simulated evidence, not a real agreement or booking]";
            }
            $headers = [['name' => 'From', 'value' => $from['name'].' <'.$from['email'].'>'], ['name' => 'To', 'value' => $c->account_email], ['name' => 'Subject', 'value' => $subject], ['name' => 'Message-ID', 'value' => '<'.$id.'@fictional.example>']];
            if ($dispatch) {
                $headers[] = ['name' => 'In-Reply-To', 'value' => $dispatch->internet_id];
            }
            $message = ['id' => $id, 'threadId' => $dispatch?->provider_thread_id ?: 'fictional-thread-'.$c->id, 'labelIds' => ['INBOX'], 'internalDate' => (string) ($planId ? $plan->clock()->addMinute()->getTimestampMs() : now()->getTimestampMs()), '_lrs_business_preview' => true, 'payload' => ['partId' => '0', 'mimeType' => 'text/plain', 'headers' => $headers, 'body' => ['size' => strlen($text), 'data' => GmailMime::encode($text)]]];
            $state['sequence']++;
            $state['messages'][$id] = $message;
            $state['history'][] = ['id' => 'fixture-history-'.$state['sequence'], '_sequence' => $state['sequence'], 'messagesAdded' => [['message' => ['id' => $id, 'labelIds' => ['INBOX']]]]];
            $this->state($c, $state);
        });
    }

    /** Parse MIME produced by this application's Symfony builder for local fixtures only. */
    public static function payload(string $raw, string $partId = '0'): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $head = preg_replace("/\r\n[ \t]+/", ' ', $head);
        $headers = [];
        $map = [];
        foreach (explode("\r\n", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $value = iconv_mime_decode(trim($value), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            $headers[] = ['name' => $name, 'value' => $value];
            $map[strtolower($name)] = $value;
        }
        $type = $map['content-type'] ?? 'text/plain';
        $part = ['partId' => $partId, 'mimeType' => strtolower(trim(explode(';', $type)[0])), 'headers' => $headers, 'filename' => ''];
        if (preg_match('/boundary="?([^"; ]+)/', $type, $m)) {
            $chunks = explode('--'.$m[1], $body);
            $part['parts'] = [];
            foreach (array_slice($chunks, 1) as $i => $chunk) {
                if (str_starts_with($chunk, '--')) {
                    break;
                }
                $part['parts'][] = self::payload(preg_replace('/^\r\n|\r\n$/', '', $chunk), $partId.'.'.$i);
            }
            $part['body'] = ['size' => 0];
        } else {
            $bytes = match (strtolower($map['content-transfer-encoding'] ?? '')) {
                'base64' => base64_decode($body, true), 'quoted-printable' => quoted_printable_decode($body), default => $body
            };
            if ($bytes === false) {
                throw new GmailFailure(400);
            }
            $part['body'] = ['size' => strlen($bytes), 'data' => GmailMime::encode($bytes)];
            if (preg_match('/filename\*=utf-8\x27\x27([^;]+)/i', $map['content-disposition'] ?? '', $m)) {
                $part['filename'] = rawurldecode($m[1]);
            } elseif (preg_match('/filename="?([^";]+)"?/i', $map['content-disposition'] ?? '', $m)) {
                $part['filename'] = $m[1];
            }
        }

        return $part;
    }
}
