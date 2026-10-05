<?php

namespace App\Support;

use App\Models\ClientQuotationRevision;
use App\Models\InquiryDocument;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class GmailMime
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $value)) {
            throw new GmailFailure(400);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new GmailFailure(400);
        }

        return $decoded;
    }

    public static function subject(string $value): string
    {
        return preg_replace('/^(?:\\s*re:\\s*)+/i', '', trim($value));
    }

    private function address(array $a): Address
    {
        $email = $a['email'] ?? '';
        $name = $a['name'] ?? '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\\r\\n\\x00]/', $email.$name)) {
            Processing::fail('The approved MIME address is unsafe. Prepare a new reviewed revision.');
        }

        return new Address($email, $name);
    }

    public function build(MailDispatch $d, array $snapshot): string
    {
        $s = $snapshot['content'];
        $e = $snapshot['envelope'];
        if (preg_match('/[\\r\\n\\x00]/', $s['subject'])) {
            Processing::fail('The approved subject contains an unsafe header value.');
        }
        $mail = (new Email)->from($this->address($e['from']))->to($this->address($s['to']))->replyTo($this->address($e['reply_to']))->subject($s['subject'])->text($s['body'], 'utf-8')->date($d->requested_at);
        foreach ($s['cc'] as $a) {
            $mail->addCc($this->address($a));
        }
        $mail->getHeaders()->addIdHeader('Message-ID', $d->dispatch_key.'@lrs.invalid');
        $mail->getHeaders()->addTextHeader('X-LRS-Dispatch-ID', $d->dispatch_key);
        if (! empty($s['thread_provider_id'])) {
            if (empty($s['in_reply_to']) || empty($s['original_subject']) || self::subject($s['subject']) !== self::subject($s['original_subject'])) {
                Processing::fail('A Gmail reply needs its exact RFC parent ID, thread and matching subject. Review a new activation.');
            }
            foreach (['In-Reply-To' => $s['in_reply_to'], 'References' => $s['references'] ?? $s['in_reply_to']] as $name => $value) {
                preg_match_all('/<([^<>\\s]+)>/', $value, $ids);
                if (! $ids[1] || preg_match('/[\\r\\n\\x00]/', $value)) {
                    Processing::fail('Unsafe or missing approved RFC threading headers.');
                }
                $mail->getHeaders()->addIdHeader($name, $ids[1]);
            }
        }
        $total = 0;
        foreach ($s['manifest'] as $f) {
            if (InquiryUploads::safeName($f['name']) !== $f['name'] || preg_match('/[\\r\\n\\x00]/', $f['mime'])) {
                Processing::fail('An approved filename or MIME type is unsafe. A new reviewed manifest is required.');
            }
            $doc = isset($f['quotation_revision_id']) ? ClientQuotationRevision::findOrFail($f['quotation_revision_id']) : InquiryDocument::findOrFail($f['document_id']);
            $bytes = isset($f['quotation_revision_id']) ? QuotationPdf::bytes($doc) : Storage::disk('inquiry_documents')->get($doc->storage_path);
            if (strlen($bytes) !== $f['size'] || ! hash_equals($f['checksum'], hash('sha256', $bytes))) {
                Processing::fail('An approved attachment changed. No Gmail submission.');
            }
            $total += strlen($bytes);
            $mail->attach($bytes, $f['name'], $f['mime']);
        }
        $raw = $mail->toString();
        $c = $d->envelope->mailbox;
        if ($total > 25000000 || strlen($raw) > min(35000000, $c->transport_limit) || strlen(self::encode($raw)) > 48000000) {
            Processing::fail('The exact encoded Gmail message or attachments exceed the configured provider limit. Reduce files with a new approved manifest; no file is omitted.');
        }

        return $raw;
    }

    public static function headers(array $message): array
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $h) {
            $key = strtolower($h['name']);
            $value = preg_match('/=\\?[^?]+\\?[bq]\\?/i', $h['value']) ? iconv_mime_decode($h['value'], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $h['value'];
            $headers[$key] = isset($headers[$key]) ? $headers[$key].', '.$value : $value;
        }

        return $headers;
    }

    public static function addresses(string $value): array
    {
        $result = [];
        if (trim($value) === '') {
            return [];
        }
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) as $piece) {
            try {
                $a = Address::create(trim($piece));
                $result[] = ['email' => mb_strtolower($a->getAddress()), 'name' => $a->getName()];
            } catch (\Throwable $e) {
                throw new GmailFailure(400);
            }
        }

        return $result;
    }

    public static function parts(array $part): array
    {
        $files = [];
        $text = [];
        $html = [];
        $walk = function (array $p, int $depth = 0) use (&$walk, &$files, &$text, &$html): void {
            if ($depth > 20 || count($files) > 100) {
                throw new GmailFailure(413);
            }
            $disposition = collect($p['headers'] ?? [])->first(fn ($h) => strtolower($h['name']) === 'content-disposition')['value'] ?? '';
            if (! empty($p['filename']) || preg_match('/^(attachment|inline)\\b/i', $disposition) || (empty($p['parts']) && ! in_array($p['mimeType'] ?? '', ['text/plain', 'text/html'], true) && ! str_starts_with($p['mimeType'] ?? '', 'multipart/'))) {
                $files[] = $p;
            } elseif (empty($p['parts']) && ($p['mimeType'] ?? '') === 'text/plain') {
                $text[] = self::decode($p['body']['data'] ?? '');
            } elseif (empty($p['parts']) && ($p['mimeType'] ?? '') === 'text/html') {
                $html[] = self::decode($p['body']['data'] ?? '');
            }
            foreach ($p['parts'] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        $walk($part);

        return ['files' => $files, 'text' => implode("\n", $text), 'html' => implode("\n", $html)];
    }

    public function attachment(MailboxConnection $c, string $messageId, array $part, ?int $maxBytes = null): string
    {
        if (isset($part['body']['data'])) {
            $data = $part['body'];
        } else {
            $id = $part['body']['attachmentId'] ?? null;
            if (! $id) {
                throw new GmailFailure(400);
            }
            $data = app(GmailMail::class)->call($c, 'GET', '/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($id));
        }
        if (! isset($data['data']) || ! is_string($data['data'])) {
            throw new GmailFailure(400);
        }
        if ($maxBytes !== null && strlen($data['data']) > (int) ceil($maxBytes / 3) * 4) {
            throw new GmailFailure(413);
        }
        $bytes = self::decode($data['data']);
        if ($maxBytes !== null && strlen($bytes) > $maxBytes) {
            throw new GmailFailure(413);
        }

        return $bytes;
    }

    private function textParts(MailboxConnection $c, string $messageId, array $payload): array
    {
        $remaining = min(1048576, (int) config('mailbox.message_max_bytes'));
        $nodes = 0;
        $walk = function (array $part, int $depth = 0) use (&$walk, &$remaining, &$nodes, $c, $messageId): array {
            if ($depth > 20 || ++$nodes > 500) {
                throw new GmailFailure(413);
            }
            $disposition = collect($part['headers'] ?? [])->first(fn ($header) => strtolower($header['name']) === 'content-disposition')['value'] ?? '';
            if (empty($part['parts']) && empty($part['filename']) && ! preg_match('/^(attachment|inline)\b/i', $disposition) && in_array($part['mimeType'] ?? '', ['text/plain', 'text/html'], true)) {
                if (($part['body']['size'] ?? 0) > $remaining) {
                    throw new GmailFailure(413);
                }
                if (! empty($part['body']['attachmentId']) && empty($part['body']['data'])) {
                    unset($part['body']['data']);
                }
                if (! isset($part['body']['data']) && empty($part['body']['attachmentId']) && ($part['body']['size'] ?? 0) === 0) {
                    $part['body']['data'] = '';
                }
                $bytes = $this->attachment($c, $messageId, $part, $remaining);
                $remaining -= strlen($bytes);
                $part['body']['data'] = self::encode($bytes);
            }
            foreach ($part['parts'] ?? [] as $index => $child) {
                $part['parts'][$index] = $walk($child, $depth + 1);
            }

            return $part;
        };

        return self::parts($walk($payload));
    }

    public function verify(MailboxConnection $c, array $message, array $snapshot, MailDispatch $d, bool $draft = true): void
    {
        $s = $snapshot['content'];
        $e = $snapshot['envelope'];
        $h = self::headers($message);
        $parts = $this->textParts($c, $message['id'], $message['payload'] ?? []);
        $canonical = fn ($v) => rtrim(str_replace("\r\n", "\n", $v), "\n");
        $emails = function ($v): array {
            $a = array_column(self::addresses($v), 'email');
            sort($a);

            return $a;
        };
        $identity = fn (string $value, array $expected): bool => self::addresses($value) === self::addresses($this->address($expected)->toString());
        $expectedCc = array_column($s['cc'], 'email');
        sort($expectedCc);
        $internet = '<'.$d->dispatch_key.'@lrs.invalid>';
        $bad = ($draft && ! in_array('DRAFT', $message['labelIds'] ?? [], true)) ||
            ($h['subject'] ?? '') !== $s['subject'] || $canonical($parts['text']) !== $canonical($s['body']) || $parts['html'] !== '' ||
            $emails($h['to'] ?? '') !== [$s['to']['email']] || $emails($h['cc'] ?? '') !== $expectedCc || ! empty($h['bcc']) ||
            ! $identity($h['from'] ?? '', $e['from']) || ! $identity($h['reply-to'] ?? '', $e['reply_to']) ||
            ($h['message-id'] ?? '') !== $internet || ($h['x-lrs-dispatch-id'] ?? '') !== $d->dispatch_key ||
            (isset($h['sender']) && $emails($h['sender']) !== [$e['sender']['email']]);
        if (! empty($s['thread_provider_id'])) {
            $bad = $bad || ($message['threadId'] ?? '') !== $s['thread_provider_id'] || trim($h['in-reply-to'] ?? '') !== trim($s['in_reply_to']) || trim($h['references'] ?? '') !== trim($s['references'] ?? $s['in_reply_to']);
        }
        $expected = $s['manifest'];
        foreach ($parts['files'] as $part) {
            $disposition = collect($part['headers'] ?? [])->first(fn ($h) => strtolower($h['name']) === 'content-disposition')['value'] ?? '';
            if (str_starts_with(strtolower($disposition), 'inline')) {
                $bad = true;
            }
            $bytes = $this->attachment($c, $message['id'], $part);
            $match = null;
            foreach ($expected as $i => $file) {
                if ($file['name'] === $part['filename'] && $file['mime'] === ($part['mimeType'] ?? '') && $file['size'] === strlen($bytes) && hash_equals($file['checksum'], hash('sha256', $bytes))) {
                    $match = $i;
                    break;
                }
            }
            if ($match === null) {
                $bad = true;
            } else {
                unset($expected[$match]);
            }
        }
        if ($bad || $expected) {
            app(MailRelease::class)->fail('The Gmail message differs from the exact authorized content, sender, recipients, thread or private attachment manifest. It will not be sent or treated as conclusive SENT evidence.');
        }
    }

    public function original(array $source): array
    {
        $e = $source['gmail_original'] ?? null;
        if (! $e) {
            throw new GmailFailure(400);
        }
        $json = Crypt::decryptString(Storage::disk('mailbox')->get($e['path']));
        if (! hash_equals($e['checksum'], hash('sha256', $json))) {
            throw new GmailFailure(400);
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function incoming(MailboxConnection $c, array $message): array
    {
        $h = self::headers($message);
        $parts = $this->textParts($c, $message['id'], $message['payload'] ?? []);
        $json = json_encode($message, JSON_THROW_ON_ERROR);
        if (strlen($json) > 48 * 1024 * 1024) {
            throw new GmailFailure(413);
        }
        $checksum = hash('sha256', $json);
        $path = 'originals/gmail/'.$c->id.'/'.hash('sha256', $message['id']).'/'.$checksum;
        Storage::disk('mailbox')->put($path, Crypt::encryptString($json));
        $from = self::addresses($h['from'] ?? '')[0] ?? ['email' => '', 'name' => ''];
        $recipients = fn ($v) => array_map(fn ($a) => ['emailAddress' => ['address' => $a['email'], 'name' => $a['name']]], self::addresses($v));

        return ['fictional_business_preview' => $c->is_demo && ! empty($message['_lrs_business_preview']), 'id' => $message['id'], 'subject' => $h['subject'] ?? '(No subject)', 'from' => ['emailAddress' => ['address' => $from['email'], 'name' => $from['name']]], 'toRecipients' => $recipients($h['to'] ?? ''), 'ccRecipients' => $recipients($h['cc'] ?? ''), 'body' => ['contentType' => $parts['text'] !== '' ? 'text' : 'html', 'content' => $parts['text'] !== '' ? $parts['text'] : $parts['html']], 'internetMessageId' => $h['message-id'] ?? '', 'internetMessageHeaders' => $message['payload']['headers'] ?? [], 'receivedDateTime' => CarbonImmutable::createFromTimestampMs($message['internalDate'])->toIso8601String(), 'conversationId' => $message['threadId'] ?? null, 'isDraft' => in_array('DRAFT', $message['labelIds'] ?? [], true), 'hasAttachments' => (bool) $parts['files'], 'gmail_labels' => $message['labelIds'] ?? [], 'gmail_original' => ['path' => $path, 'checksum' => $checksum], 'gmail_attachment_count' => count($parts['files'])];
    }

    public function attachments(array $source): array
    {
        $message = $this->original($source);

        return array_map(fn ($p) => ['id' => $p['partId'], 'name' => ($p['filename'] ?: 'document'), 'contentType' => $p['mimeType'], 'size' => $p['body']['size'] ?? 0, '@odata.type' => 'gmail.file', 'isInline' => collect($p['headers'] ?? [])->contains(fn ($h) => strtolower($h['name']) === 'content-disposition' && str_starts_with(strtolower($h['value']), 'inline')), 'gmail_part' => $p], self::parts($message['payload'])['files']);
    }
}
