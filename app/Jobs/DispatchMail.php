<?php

namespace App\Jobs;

use App\Actions\MailOutbox;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\User;
use App\Support\Audit;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\Mailboxes;
use App\Support\MailRelease;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DispatchMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $dispatchId) {}

    public function handle(): void
    {
        $lease = (string) Str::uuid();
        $d = DB::transaction(function () use ($lease): ?MailDispatch {
            $d = MailDispatch::whereKey($this->dispatchId)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['queued', 'preparing', 'ready'], true) || $d->lease_until?->isFuture() || $d->next_attempt_at?->isFuture()) {
                return null;
            }
            $d->update(['lease' => $lease, 'lease_until' => now()->addSeconds(360), 'attempts' => $d->attempts + 1]);

            return $d;
        });
        if (! $d) {
            return;
        }
        $graph = app(GraphMail::class);
        $outbox = app(MailOutbox::class);
        try {
            $staff = User::findOrFail($d->requested_by);
            $snapshot = app(MailRelease::class)->preflight($d->envelope, $staff);
            $c = MailboxConnection::current();
            $s = $snapshot['content'];
            $env = $snapshot['envelope'];
            $base = GraphMail::messages($env['target_id']);
            if (! $d->provider_draft_id) {
                if ($d->draft_started_at) {
                    $items = $graph->correlated($c, $env['target_id'], $d->dispatch_key);
                    if (count($items) !== 1 || empty($items[0]['isDraft'])) {
                        $this->update($lease, ['status' => 'uncertain', 'last_error' => 'Draft creation ended without an exact persisted identity. Reconcile; do not create another draft.']);
                        $outbox->event($d, 'uncertain', 'Unresolved draft creation. No automatic replacement or send.');

                        return;
                    }
                    $draft = $items[0];
                } else {
                    if (! $this->update($lease, ['status' => 'preparing', 'draft_started_at' => now()])) {
                        return;
                    }
                    $draft = $graph->call($c, 'POST', $base, [
                        'subject' => $s['subject'], 'body' => ['contentType' => 'Text', 'content' => $s['body']],
                        'toRecipients' => [$this->recipient($s['to'])], 'ccRecipients' => array_map($this->recipient(...), $s['cc']),
                        'from' => $this->recipient($env['from']), 'replyTo' => [$this->recipient($env['reply_to'])],
                        'internetMessageHeaders' => [['name' => 'X-LRS-Dispatch-ID', 'value' => $d->dispatch_key]],
                        'singleValueExtendedProperties' => [['id' => config('mailbox.extended_property'), 'value' => $d->dispatch_key]],
                    ]);
                }
                if (empty($draft['id'])) {
                    throw new GraphFailure(0, 30, true);
                }
                if (! $this->update($lease, ['provider_draft_id' => $draft['id'], 'status' => 'preparing'])) {
                    return;
                }
                $d = $d->fresh();
            }
            $path = $base.'/'.rawurlencode($d->provider_draft_id);
            $inventory = $this->inventory($graph, $c, $path);
            $expected = $s['manifest'];
            $remaining = $expected;
            foreach ($inventory as $file) {
                $index = null;
                foreach ($remaining as $i => $item) {
                    if ($item['name'] === $file['name'] && $item['size'] === $file['size'] && $item['mime'] === $file['mime'] && ! $file['is_inline'] && hash_equals($item['checksum'], $file['checksum'])) {
                        $index = $i;
                        break;
                    }
                }
                if ($index === null) {
                    app(MailRelease::class)->fail('The provider draft contains an extra, duplicate or changed attachment. It will not be sent.');
                }
                unset($remaining[$index]);
            }
            if ($remaining) {
                $file = reset($remaining);
                $doc = InquiryDocument::findOrFail($file['document_id']);
                $bytes = Storage::disk('inquiry_documents')->get($doc->storage_path);
                if (strlen($bytes) !== $file['size'] || hash('sha256', $bytes) !== $file['checksum']) {
                    app(MailRelease::class)->fail('An approved file changed. No provider submission.');
                }
                if ($file['size'] < 3000000) {
                    $graph->call($c, 'POST', $path.'/attachments', ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $file['name'], 'contentType' => $file['mime'], 'contentBytes' => base64_encode($bytes)]);
                } else {
                    $upload = $d->upload_state;
                    if ($upload && isset($upload['expires_at']) && CarbonImmutable::parse($upload['expires_at'])->isPast()) {
                        $upload = null;
                        $this->update($lease, ['upload_state' => null]);
                    }
                    if (! $upload || ($upload['document_id'] ?? null) !== $doc->id) {
                        $session = $graph->call($c, 'POST', $path.'/attachments/createUploadSession', ['AttachmentItem' => ['attachmentType' => 'file', 'name' => $file['name'], 'size' => $file['size'], 'contentType' => $file['mime']]]);
                        $upload = ['document_id' => $doc->id, 'url' => $session['uploadUrl'], 'expires_at' => $session['expirationDateTime']];
                        if (! $this->update($lease, ['upload_state' => $upload])) {
                            return;
                        }
                    }
                    try {
                        $session = $graph->upload($c, $upload['url'], 'GET');
                    } catch (GraphFailure $e) {
                        if (! in_array($e->status, [404, 410], true)) {
                            throw $e;
                        } $this->update($lease, ['upload_state' => null, 'next_attempt_at' => now()->addSecond()]);

                        return;
                    }
                    $range = $session['nextExpectedRanges'][0] ?? '0-';
                    $offset = (int) explode('-', $range)[0];
                    $length = min(1048576, $file['size'] - $offset);
                    if ($length <= 0) {
                        throw new GraphFailure(400);
                    }
                    $result = $graph->upload($c, $upload['url'], 'PUT', substr($bytes, $offset, $length), 'bytes '.$offset.'-'.($offset + $length - 1).'/'.$file['size']);
                    if ($result['status'] === 201) {
                        $this->update($lease, ['upload_state' => null]);
                    }
                }
                $this->update($lease, ['next_attempt_at' => now()->addSecond()]);

                return;
            }
            $draft = $graph->call($c, 'GET', $path.'?'.http_build_query(['$select' => 'id,isDraft,subject,body,toRecipients,ccRecipients,bccRecipients,from,sender,replyTo,internetMessageId']), text: true);
            $this->verify($draft, $s, $env);
            if (! $this->update($lease, ['status' => 'ready', 'internet_id' => $draft['internetMessageId'] ?? null, 'upload_state' => null])) {
                return;
            }
            $allowed = DB::transaction(function () use ($d, $staff, $lease): bool {
                $latest = MailDispatch::whereKey($d->id)->lockForUpdate()->firstOrFail();
                if ($latest->lease !== $lease || $latest->status !== 'ready') {
                    return false;
                }
                app(MailRelease::class)->preflight($latest->envelope, $staff, true);
                $latest->update(['status' => 'submitting', 'submission_started_at' => $latest->submission_started_at ?? now()]);
                app(MailOutbox::class)->event($latest, 'submitting', 'Frozen submission point reached. Later cancellation/disconnection cannot recall an in-flight request.');

                return true;
            });
            if (! $allowed) {
                return;
            }
            if (! MailboxConnection::current()->usable()) {
                throw new GraphFailure(401);
            }
            $response = $graph->call($c, 'POST', $path.'/send');
            if (($response['status'] ?? null) !== 202) {
                throw new GraphFailure(0, 30, true);
            }
            $this->update($lease, ['status' => 'accepted', 'accepted_at' => now(), 'last_error' => null, 'next_attempt_at' => now()->addSeconds(30)]);
            $outbox->event($d, 'accepted', 'Graph accepted for processing (202). Delivery and reading remain unconfirmed.');
            if ($d->envelope->clarification_id) {
                DB::transaction(function () use ($d): void {
                    $i = Inquiry::whereKey($d->envelope->inquiry_id)->lockForUpdate()->firstOrFail();
                    $item = $d->envelope->clarification;
                    if ($i->status === 'needs_review' && $item->currentFor($i)) {
                        $before = Audit::snapshot($i);
                        $i->update(['status' => 'needs_client_information', 'lock_version' => $i->lock_version + 1]);
                        Audit::record('Approved clarification accepted by Outlook; awaiting client information', $i, $before, systemActor: 'System / Outlook');
                    }
                });
            }
            ReconcileMail::dispatch($d->id)->onQueue('mail')->delay(now()->addSeconds(30));
        } catch (ValidationException $e) {
            $note = implode(' ', array_merge(...array_values($e->errors())));
            $this->update($lease, ['status' => 'failed', 'last_error' => $note]);
            $outbox->event($d, 'blocked', $note);
        } catch (GraphFailure $e) {
            $current = $d->fresh();
            if (in_array($e->status, [401, 403], true)) {
                app(Mailboxes::class)->pause($e->getMessage(), $c);
            }
            if (($current->status === 'submitting' || ($current->draft_started_at && ! $current->provider_draft_id)) && ($e->ambiguous || $e->status === 0 || $e->status >= 500)) {
                $this->update($lease, ['status' => 'uncertain', 'last_error' => $e->getMessage(), 'next_attempt_at' => now()->addSeconds(30)]);
                $outbox->event($d, 'uncertain', 'The send call has an unknown outcome. Only reconciliation is allowed.');
            } elseif ($e->status === 429 && $current->attempts < 6) {
                if (! $current->provider_draft_id) {
                    $this->update($lease, ['draft_started_at' => null]);
                }
                $this->update($lease, ['status' => $current->status === 'submitting' ? 'ready' : $current->status, 'next_attempt_at' => now()->addSeconds($e->retryAfter), 'last_error' => $e->getMessage()]);
                $outbox->event($d, 'throttled', 'Provider rejected this attempt. Retry-After '.$e->retryAfter.' seconds.');
            } elseif (! $current->submission_started_at && $current->provider_draft_id && ($e->status === 0 || $e->status >= 500) && $current->attempts < 6) {
                $this->update($lease, ['status' => 'preparing', 'next_attempt_at' => now()->addSeconds(30), 'last_error' => $e->getMessage()]);
            } else {
                if (! $current->provider_draft_id && ! $e->ambiguous && $e->status >= 400 && $e->status < 500) {
                    $this->update($lease, ['draft_started_at' => null]);
                }
                $this->update($lease, ['status' => 'failed', 'last_error' => $e->getMessage(), 'upload_state' => $current->submission_started_at ? ['send_rejected' => ! $e->ambiguous && $e->status >= 400 && $e->status < 500] : $current->upload_state]);
                $outbox->event($d, 'failed', $e->getMessage());
            }
        } catch (\Throwable $e) {
            $current = $d->fresh();
            $uncertain = $current->submission_started_at !== null || ($current->draft_started_at && ! $current->provider_draft_id);
            $this->update($lease, ['status' => $uncertain ? 'uncertain' : 'failed', 'last_error' => 'Mailbox work stopped unexpectedly. Inspect persisted evidence before recovery.']);
        } finally {
            MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null]);
        }
    }

    private function update(string $lease, array $values): bool
    {
        $d = MailDispatch::find($this->dispatchId);
        if (! $d || $d->lease !== $lease || $d->status === 'cancelled') {
            return false;
        }

        return DB::transaction(function () use ($lease, $values): bool {
            $d = MailDispatch::whereKey($this->dispatchId)->where('lease', $lease)->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            if (! $d) {
                return false;
            } $d->fill($values)->save();

            return true;
        });
    }

    private function recipient(array $recipient): array
    {
        return ['emailAddress' => ['address' => $recipient['email'], 'name' => $recipient['name'] ?? '']];
    }

    private function addresses(array $recipients): array
    {
        $emails = array_map(fn (array $r): string => mb_strtolower($r['emailAddress']['address'] ?? ''), $recipients);
        sort($emails);

        return $emails;
    }

    private function verify(array $draft, array $s, array $env): void
    {
        $normalize = fn (string $value): string => str_replace("\r\n", "\n", $value);
        if (empty($draft['isDraft']) || ($draft['subject'] ?? '') !== $s['subject'] || $normalize($draft['body']['content'] ?? '') !== $normalize($s['body']) || $this->addresses($draft['toRecipients'] ?? []) !== [$s['to']['email']] || $this->addresses($draft['ccRecipients'] ?? []) !== $this->addresses(array_map($this->recipient(...), $s['cc'])) || ! empty($draft['bccRecipients']) || $this->addresses($draft['replyTo'] ?? []) !== [$env['reply_to']['email']] || mb_strtolower($draft['from']['emailAddress']['address'] ?? '') !== $env['from']['email'] || (isset($draft['sender']['emailAddress']['address']) && mb_strtolower($draft['sender']['emailAddress']['address']) !== $env['sender']['email'])) {
            app(MailRelease::class)->fail('The provider draft differs from the authorized recipients, subject, body or envelope. It will not be sent. Verify mailbox rights and prepare a fresh review.');
        }
    }

    private function inventory(GraphMail $graph, MailboxConnection $c, string $path): array
    {
        $items = $graph->list($c, $path.'/attachments?$select=id,name,size,contentType,isInline', config('inquiries.document_limit'));
        $files = [];
        foreach ($items as $item) {
            if (($item['@odata.type'] ?? '#microsoft.graph.fileAttachment') !== '#microsoft.graph.fileAttachment') {
                app(MailRelease::class)->fail('The provider draft contains an unsupported attachment.');
            }
            $bytes = $graph->call($c, 'GET', $path.'/attachments/'.rawurlencode($item['id']).'/$value');
            $files[] = ['name' => $item['name'], 'size' => strlen($bytes), 'mime' => $item['contentType'] ?? '', 'is_inline' => $item['isInline'] ?? false, 'checksum' => hash('sha256', $bytes)];
        }

        return $files;
    }
}
