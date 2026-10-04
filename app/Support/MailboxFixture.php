<?php

namespace App\Support;

use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MailboxFixture
{
    public function call(MailboxConnection $c, string $method, string $url, array $data): array|string
    {
        return Cache::lock('mailbox-fixture-state', 60)->block(10, fn () => $this->perform($c, $method, $url, $data));
    }

    public function upload(string $url, string $method, ?string $bytes, ?string $range): array
    {
        return Cache::lock('mailbox-fixture-state', 60)->block(10, fn () => $this->performUpload($url, $method, $bytes, $range));
    }

    public function loadIncoming(): void
    {
        Cache::lock('mailbox-fixture-state', 60)->block(10, fn () => $this->performLoadIncoming());
    }

    private function read(): array
    {
        $disk = Storage::disk('mailbox');

        return $disk->exists('fixture-state.enc') ? json_decode(Crypt::decryptString($disk->get('fixture-state.enc')), true, flags: JSON_THROW_ON_ERROR) : ['messages' => [], 'uploads' => []];
    }

    private function write(array $state): void
    {
        Storage::disk('mailbox')->put('fixture-state.enc', Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)));
    }

    private function perform(MailboxConnection $c, string $method, string $url, array $data): array|string
    {
        abort_unless($c->is_demo && config('mailbox.demo_enabled') && app()->environment('local', 'testing'), 403);
        $state = $this->read();
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (preg_match('~/mailFolders/([^/]+)$~', $path, $match)) {
            return ['id' => rawurldecode($match[1]), 'displayName' => ucfirst($match[1])];
        }
        if (str_ends_with($path, '/mailFolders')) {
            return ['value' => [['id' => 'inbox', 'displayName' => 'Fixture Inbox'], ['id' => 'sentitems', 'displayName' => 'Fixture Sent Items'], ['id' => 'review', 'displayName' => 'Fixture Review folder']]];
        }
        if (str_ends_with($path, '/messages/delta')) {
            $sent = str_contains(strtolower($path), '/sentitems/');
            $items = array_values(array_filter($state['messages'], fn (array $m): bool => $sent ? (! $m['isDraft'] && ($m['_direction'] ?? '') === 'outgoing') : ($m['_direction'] ?? '') === 'incoming'));
            $start = (int) ($query['_fixture_offset'] ?? 0);
            $page = array_slice($items, $start, 25);
            $next = $start + count($page);
            $more = $next < count($items);
            $base = 'https://graph.microsoft.com'.(str_starts_with($path, '/v1.0/') ? '' : '/v1.0').$path;

            return ['value' => array_map(fn (array $m): array => array_diff_key($m, array_flip(['attachments', '_direction'])), $page), $more ? '@odata.nextLink' : '@odata.deltaLink' => $base.'?'.http_build_query(['_fixture_offset' => $next])];
        }
        if (preg_match('~/messages(?:/([^/]+))?(.*)$~', $path, $match)) {
            $id = isset($match[1]) ? rawurldecode($match[1]) : null;
            $tail = $match[2] ?? '';
            if (! $id && $method === 'POST') {
                $id = 'fixture-'.Str::uuid();
                $data += ['id' => $id, 'isDraft' => true, 'internetMessageId' => '<'.$id.'@example.test>', 'sender' => ['emailAddress' => ['address' => $c->send_mode === 'on_behalf' ? $c->account_email : $c->target_email, 'name' => 'Synthetic sender']], 'receivedDateTime' => now()->toIso8601String(), 'attachments' => [], '_direction' => 'outgoing'];
                $state['messages'][$id] = $data;
                $this->write($state);

                return $data;
            }
            if (! $id) {
                preg_match("/ep\/value eq '([0-9a-f-]+)'/i", $query['$filter'] ?? '', $key);
                $items = array_values(array_filter($state['messages'], fn (array $m): bool => isset($key[1]) && collect($m['singleValueExtendedProperties'] ?? [])->contains(fn (array $p): bool => $p['value'] === $key[1])));

                return ['value' => $items];
            }
            if (! isset($state['messages'][$id])) {
                throw new GraphFailure(404);
            }
            $m = $state['messages'][$id];
            if ($tail === '/send' && $method === 'POST') {
                if (! $m['isDraft']) {
                    throw new GraphFailure(400);
                }
                $state['messages'][$id]['isDraft'] = false;
                $state['messages'][$id]['sentDateTime'] = now()->toIso8601String();
                $this->write($state);

                return ['status' => 202];
            }
            if ($tail === '/attachments/createUploadSession' && $method === 'POST') {
                $key = (string) Str::uuid();
                $state['uploads'][$key] = ['message_id' => $id, 'name' => $data['AttachmentItem']['name'], 'size' => $data['AttachmentItem']['size'], 'mime' => $data['AttachmentItem']['contentType'] ?? 'application/octet-stream', 'bytes' => ''];
                $this->write($state);

                return ['uploadUrl' => 'https://outlook.office.com/api/v2.0/fixture/AttachmentSessions('.$key.')?fixture=1', 'expirationDateTime' => now()->addHour()->toIso8601String(), 'nextExpectedRanges' => ['0-']];
            }
            if ($tail === '/attachments' && $method === 'POST') {
                $data += ['id' => 'fixture-file-'.Str::uuid(), 'size' => strlen(base64_decode($data['contentBytes'])), 'isInline' => false];
                $state['messages'][$id]['attachments'][] = $data;
                $this->write($state);

                return $data;
            }
            if ($tail === '/attachments') {
                return ['value' => array_map(fn (array $a): array => array_diff_key($a, ['contentBytes' => true]), $m['attachments'] ?? [])];
            }
            if (preg_match('~^/attachments/([^/]+)/\$value$~', $tail, $a)) {
                foreach ($m['attachments'] ?? [] as $file) {
                    if ($file['id'] === rawurldecode($a[1])) {
                        return base64_decode($file['contentBytes'], true);
                    }
                } throw new GraphFailure(404);
            }

            return $m;
        }
        throw new GraphFailure(400);
    }

    private function performUpload(string $url, string $method, ?string $bytes, ?string $range): array
    {
        $state = $this->read();
        preg_match('/AttachmentSessions\(([^)]+)\)/', $url, $match);
        $key = $match[1] ?? '';
        if (! isset($state['uploads'][$key])) {
            throw new GraphFailure(404);
        }
        $s = $state['uploads'][$key];
        $current = base64_decode($s['bytes']);
        if ($method === 'GET') {
            return ['status' => 200, 'nextExpectedRanges' => [strlen($current).'-']];
        }
        preg_match('/bytes (\d+)-(\d+)\/(\d+)/', $range ?? '', $r);
        if (! isset($r[3]) || (int) $r[1] !== strlen($current)) {
            throw new GraphFailure(400);
        }
        $current .= $bytes;
        $state['uploads'][$key]['bytes'] = base64_encode($current);
        $done = strlen($current) === $s['size'];
        if ($done) {
            $state['messages'][$s['message_id']]['attachments'][] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'fixture-large-'.$key, 'name' => $s['name'], 'size' => $s['size'], 'contentBytes' => base64_encode($current), 'contentType' => $s['mime'], 'isInline' => false];
        }
        $this->write($state);

        return ['status' => $done ? 201 : 200, 'nextExpectedRanges' => [strlen($current).'-']];
    }

    private function performLoadIncoming(): void
    {
        $c = MailboxConnection::current();
        abort_unless($c->is_demo && $c->usable(), 403);
        $state = $this->read();
        $base = ['isDraft' => false, 'receivedDateTime' => now()->toIso8601String(), 'sentDateTime' => now()->subMinute()->toIso8601String(), 'toRecipients' => [['emailAddress' => ['address' => $c->target_email]]], 'ccRecipients' => [], 'hasAttachments' => false, 'attachments' => [], '_direction' => 'incoming'];
        $samples = [
            ['id' => 'fixture-customer-1', 'subject' => 'SYNTHETIC · Request from website customer by email', 'from' => ['emailAddress' => ['name' => 'Synthetic customer', 'address' => 'customer-fixture@example.test']], 'body' => ['contentType' => 'HTML', 'content' => '<p>Synthetic shipment inquiry for review. Technical details need confirmation.</p><script>alert("unsafe")</script><img src="https://example.invalid/tracker"><p>No real client data.</p>']],
            ['id' => 'fixture-unmatched-1', 'subject' => 'Re: SYNTHETIC request · unfamiliar sender', 'from' => ['emailAddress' => ['name' => 'Synthetic unfamiliar sender', 'address' => 'unfamiliar@example.test']], 'body' => ['contentType' => 'Text', 'content' => 'Synthetic reply without reliable thread identity. Review the correct case.']],
            ['id' => 'fixture-ooo-1', 'subject' => 'Automatic reply: Out of office · SYNTHETIC', 'from' => ['emailAddress' => ['address' => 'away@example.test']], 'internetMessageHeaders' => [['name' => 'Auto-Submitted', 'value' => 'auto-replied']], 'body' => ['contentType' => 'Text', 'content' => 'Synthetic absence notice, not a quotation.']],
            ['id' => 'fixture-bounce-1', 'subject' => 'Undeliverable: SYNTHETIC RFQ', 'from' => ['emailAddress' => ['address' => 'postmaster@example.test']], 'body' => ['contentType' => 'Text', 'content' => 'Synthetic failure notice. This is separate bounce evidence, not a commercial response.']],
        ];
        foreach (MailDispatch::where('is_demo', true)->whereIn('status', ['accepted', 'observed'])->with('envelope')->limit(3)->get() as $d) {
            $s = $d->envelope->snapshot['content'];
            $samples[] = ['id' => 'fixture-reply-'.$d->dispatch_key, 'subject' => 'Re: '.$s['subject'], 'from' => ['emailAddress' => ['name' => $s['to']['name'], 'address' => $s['to']['email']]], 'internetMessageHeaders' => [['name' => 'In-Reply-To', 'value' => $d->internet_id]], 'body' => ['contentType' => 'Text', 'content' => 'SYNTHETIC quotation received. Rates are not parsed or reviewed in Phase 5.']];
        }
        foreach ($samples as $sample) {
            $id = $sample['id'];
            if (! isset($state['messages'][$id])) {
                $state['messages'][$id] = $sample + $base + ['internetMessageId' => '<'.$id.'@example.test>'];
            }
        }
        $this->write($state);
    }
}
