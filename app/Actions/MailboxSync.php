<?php

namespace App\Actions;

use App\Models\MailboxFolder;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\Mailboxes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MailboxSync
{
    public function handle(int $folderId): void
    {
        $lease = (string) Str::uuid();
        $folder = DB::transaction(function () use ($folderId, $lease): ?MailboxFolder {
            $f = MailboxFolder::whereKey($folderId)->lockForUpdate()->first();
            if (! $f || ! $f->enabled || ! $f->mailbox->usable() || $f->identity_hash !== $f->mailbox->identity_hash || $f->lease_until?->isFuture() || $f->next_attempt_at?->isFuture()) {
                return null;
            }
            $f->update(['lease' => $lease, 'lease_until' => now()->addSeconds(360)]);

            return $f;
        });
        if (! $folder) {
            return;
        }
        try {
            $page = $folder->page;
            if (! $page) {
                $path = $folder->cursor ?? 'https://graph.microsoft.com/v1.0/users/'.$folder->mailbox_id.'/mailFolders/'.rawurlencode($folder->provider_id).'/messages/delta?'.http_build_query(['$select' => 'id,subject,from,sender,toRecipients,ccRecipients,bccRecipients,replyTo,body,internetMessageId,internetMessageHeaders,receivedDateTime,sentDateTime,conversationId,hasAttachments,isDraft', '$top' => 25]);
                GraphMail::deltaUrl($path, $folder->mailbox_id, $folder->provider_id);
                $page = app(GraphMail::class)->call($folder->mailbox, 'GET', $path);
                if (! isset($page['value']) || (! isset($page['@odata.nextLink']) && ! isset($page['@odata.deltaLink']))) {
                    throw new GraphFailure(400);
                }
                foreach (['@odata.nextLink', '@odata.deltaLink'] as $key) {
                    if (isset($page[$key])) {
                        GraphMail::deltaUrl($page[$key], $folder->mailbox_id, $folder->provider_id);
                    }
                }
                DB::transaction(function () use ($folder, $lease, $page): void {
                    $f = MailboxFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
                    if ($f->lease !== $lease) {
                        throw new GraphFailure(429, 3);
                    }
                    $f->update(['page' => $page, 'offset' => 0]);
                });
                $folder = $folder->fresh();
            }
            foreach (array_slice($page['value'], $folder->offset, null, true) as $offset => $source) {
                DB::transaction(function () use ($folder, $lease, $source, $offset): void {
                    $f = MailboxFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
                    if ($f->lease !== $lease || ! $f->mailbox->usable() || $f->identity_hash !== $f->mailbox->identity_hash || ! $f->enabled) {
                        throw new GraphFailure(401);
                    }
                    app(MailIngest::class)->handle($f, $source);
                    $f->update(['offset' => $offset + 1]);
                });
            }
            DB::transaction(function () use ($folder, $lease, $page): void {
                $f = MailboxFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
                if ($f->lease !== $lease) {
                    throw new GraphFailure(429, 3);
                }
                $more = isset($page['@odata.nextLink']);
                $count = $f->cycle_count + count($page['value']);
                $limited = $more && $count >= 5000;
                $f->update(['cursor' => $page['@odata.nextLink'] ?? $page['@odata.deltaLink'], 'page' => null, 'offset' => 0, 'failure_count' => 0, 'cycle' => $f->cycle + 1, 'cycle_count' => $more ? $count : 0, 'last_sync_at' => $more ? $f->last_sync_at : now(), 'last_error' => $limited ? 'A catch-up cycle exceeded 5,000 messages. Review the retained page boundary and explicitly resume; no records or cursor were discarded.' : null, 'enabled' => ! $limited, 'next_attempt_at' => $more ? now()->addSecond() : now()->addMinute()]);
                if (! $more) {
                    $f->mailbox->update(['last_sync_at' => now(), 'last_error' => null]);
                }
            });
        } catch (GraphFailure $e) {
            $f = $folder->fresh();
            if ($e->status === 410) {
                $boundary = $f->last_sync_at?->subDays(7) ?? $f->import_from;
                $gap = $boundary->lt(now()->subDays(config('mailbox.history_days'))) || $f->resync_count >= 2;
                $f->update(['cursor' => null, 'page' => null, 'offset' => 0, 'import_from' => $boundary->gt($f->import_from) ? $boundary : $f->import_from, 'resync_count' => $f->resync_count + 1, 'enabled' => ! $gap, 'last_error' => $gap ? 'Cursor expired outside the bounded catch-up window or expired repeatedly. Admin must choose an explicit resync boundary; retained business evidence is safe.' : 'Cursor expired. Resynchronizing the bounded interval with immutable-ID deduplication.', 'next_attempt_at' => now()->addMinute()]);
            } else {
                $count = $f->failure_count + 1;
                $f->update(['failure_count' => $count, 'enabled' => $count < 6, 'last_error' => $e->getMessage().($count >= 6 ? ' Automatic retries paused after six failures. Admin must review and explicitly resume.' : ''), 'next_attempt_at' => now()->addSeconds($e->retryAfter)]);
                if (in_array($e->status, [401, 403], true)) {
                    app(Mailboxes::class)->pause($e->getMessage(), $folder->mailbox);
                }
            }
        } catch (\Throwable $e) {
            $folder->fresh()->update(['enabled' => false, 'last_error' => 'Import stopped at a retained page. The message may exceed evidence limits or need inspection. Admin can retry this page after resolving the issue.']);
        } finally {
            MailboxFolder::whereKey($folder->id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null]);
        }
    }
}
