<?php

namespace App\Actions;

use App\Models\MailboxFolder;
use App\Models\MailMessage;
use App\Support\GmailFailure;
use App\Support\GmailMail;
use App\Support\GmailMime;
use Illuminate\Support\Facades\DB;

class GmailSync
{
    public function handle(MailboxFolder $folder, string $lease): void
    {
        $gmail = app(GmailMail::class);
        if (! $folder->gmail_initial_anchor && ! $folder->gmail_history_id) {
            $profile = $gmail->call($folder->mailbox, 'GET', '/profile');
            if (! $this->opaque($profile['historyId'] ?? null, 255)) {
                throw new GmailFailure(400);
            }
            $this->update($folder, $lease, ['gmail_initial_anchor' => $profile['historyId'], 'gmail_phase' => 'initial']);
            $folder = $folder->fresh();
        }
        $page = $folder->page;
        if (! $page) {
            $initial = $folder->gmail_phase === 'initial';
            if ($initial) {
                $query = ['labelIds' => $folder->provider_id, 'q' => 'after:'.$folder->import_from->timestamp.' -in:spam -in:trash -in:sent -in:drafts', 'includeSpamTrash' => 'false', 'maxResults' => 25];
                if ($folder->gmail_page_token) {
                    $query['pageToken'] = $folder->gmail_page_token;
                }
                $r = $gmail->call($folder->mailbox, 'GET', '/messages?'.http_build_query($query));
                $items = array_map(fn ($m) => ['id' => $m['id'], 'action' => 'added'], $r['messages'] ?? []);
            } else {
                $query = ['startHistoryId' => $folder->gmail_history_id ?: $folder->gmail_initial_anchor, 'labelId' => $folder->provider_id, 'maxResults' => 25];
                if ($folder->gmail_page_token) {
                    $query['pageToken'] = $folder->gmail_page_token;
                }
                try {
                    $r = $gmail->call($folder->mailbox, 'GET', '/history?'.http_build_query($query));
                } catch (GmailFailure $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                    $this->update($folder, $lease, ['enabled' => false, 'last_error' => 'Gmail history expired. Admin must choose an explicit bounded resync. Original sources, files and cases are preserved; no automatic cursor reset.', 'next_attempt_at' => null]);

                    return;
                }
                if (! $this->opaque($r['historyId'] ?? null, 255)) {
                    throw new GmailFailure(400);
                }
                $items = [];
                foreach ($r['history'] ?? [] as $h) {
                    foreach ($h['messagesAdded'] ?? [] as $change) {
                        $items[] = ['id' => $change['message']['id'], 'action' => 'added'];
                    }
                    foreach ($h['messagesDeleted'] ?? [] as $change) {
                        $items[] = ['id' => $change['message']['id'], 'action' => 'deleted'];
                    }
                    foreach ($h['labelsRemoved'] ?? [] as $change) {
                        if (in_array($folder->provider_id, $change['labelIds'] ?? [], true)) {
                            $items[] = ['id' => $change['message']['id'], 'action' => 'label_removed'];
                        }
                    }
                    foreach ($h['labelsAdded'] ?? [] as $change) {
                        $items[] = ['id' => $change['message']['id'], 'action' => 'label_only'];
                    }
                }
            }
            if (isset($r['nextPageToken']) && ! $this->opaque($r['nextPageToken'], 8192)) {
                throw new GmailFailure(400);
            }
            foreach ($items as $item) {
                if (! $this->opaque($item['id'] ?? null, 512)) {
                    throw new GmailFailure(400);
                }
            }
            if (count($items) > 500 || strlen(json_encode($r, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024) {
                throw new GmailFailure(413);
            }
            $page = ['items' => $items, 'next' => $r['nextPageToken'] ?? null, 'history' => $r['historyId'] ?? null, 'initial' => $initial];
            $this->update($folder, $lease, ['page' => $page, 'offset' => 0]);
            $folder = $folder->fresh();
        }
        foreach (array_slice($page['items'], $folder->offset, null, true) as $offset => $item) {
            $source = null;
            $known = MailMessage::where('mailbox_key', $folder->mailboxKey())->where('provider_id', $item['id'])->first();
            if ($item['action'] === 'added') {
                try {
                    $m = $gmail->call($folder->mailbox, 'GET', '/messages/'.rawurlencode($item['id']).'?format=full');
                } catch (GmailFailure $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    } $m = null;
                }
                $labels = $m['labelIds'] ?? [];
                if ($m && in_array($folder->provider_id, $labels, true) && ! array_intersect(['SENT', 'DRAFT', 'SPAM', 'TRASH'], $labels)) {
                    $source = $known ? $known->source : app(GmailMime::class)->incoming($folder->mailbox, $m);
                }
            }
            DB::transaction(function () use ($folder, $lease, $source, $item, $offset, $known): void {
                $f = $this->locked($folder, $lease);
                if ($source) {
                    app(MailIngest::class)->handle($f, $source);
                } elseif ($known && in_array($item['action'], ['deleted', 'label_removed'], true)) {
                    app(MailIngest::class)->handle($f, ['id' => $item['id'], $item['action'] === 'deleted' ? '@removed' : '@label_removed' => true]);
                } elseif ($known && $item['action'] === 'label_only') {
                    DB::table('mail_folder_message')->upsert([['mailbox_folder_id' => $f->id, 'mail_message_id' => $known->id, 'removed_at' => null]], ['mailbox_folder_id', 'mail_message_id'], ['removed_at']);
                }
                $f->update(['offset' => $offset + 1]);
            });
        }
        DB::transaction(function () use ($folder, $lease, $page): void {
            $f = $this->locked($folder, $lease);
            $next = $page['next'];
            $count = $f->cycle_count + count($page['items']);
            $complete = ! $page['initial'] && ! $next;
            $values = ['page' => null, 'offset' => 0, 'gmail_page_token' => $next, 'failure_count' => 0, 'cycle' => $f->cycle + 1, 'cycle_count' => $complete ? 0 : $count, 'last_error' => null, 'next_attempt_at' => $complete ? now()->addMinute() : now()->addSecond()];
            if ($page['initial'] && ! $next) {
                $values['gmail_phase'] = 'catchup';
            }
            if ($complete) {
                $values += ['gmail_history_id' => $page['history'], 'cursor' => $page['history'], 'gmail_initial_anchor' => null, 'gmail_phase' => 'history', 'last_sync_at' => now()];
            }
            if (! $complete && $count >= 5000) {
                $values += ['enabled' => false];
                $values['last_error'] = 'Gmail catch-up reached 5,000 changes. Review and resume the retained boundary; no evidence was discarded.';
            }
            $f->update($values);
            if ($complete) {
                $f->mailbox->update(['last_sync_at' => now(), 'last_error' => null]);
            }
        });
    }

    private function opaque(mixed $value, int $limit): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private function locked(MailboxFolder $f, string $lease): MailboxFolder
    {
        $locked = MailboxFolder::whereKey($f->id)->lockForUpdate()->firstOrFail();
        if ($locked->lease !== $lease || ! $locked->enabled || ! $locked->mailbox->usable() || ! $locked->mailbox->incoming_enabled || $locked->identity_hash !== $locked->mailbox->identity_hash) {
            throw new GmailFailure(401);
        }

        return $locked;
    }

    private function update(MailboxFolder $f, string $lease, array $values): void
    {
        DB::transaction(fn () => $this->locked($f, $lease)->update($values));
    }
}
