<?php

namespace App\Jobs;

use App\Actions\MailOutbox;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\Mailboxes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $dispatchId) {}

    public function handle(): void
    {
        $lease = (string) Str::uuid();
        $d = DB::transaction(function () use ($lease): ?MailDispatch {
            $d = MailDispatch::whereKey($this->dispatchId)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['accepted', 'uncertain', 'submitting', 'observed'], true) || $d->lease_until?->isFuture() || ! MailboxConnection::current()->usable()) {
                return null;
            }
            $d->update(['lease' => $lease, 'lease_until' => now()->addSeconds(180), 'reconcile_attempts' => $d->reconcile_attempts + 1]);

            return $d;
        });
        if (! $d) {
            return;
        }
        try {
            $c = MailboxConnection::current();
            $env = $d->envelope->snapshot['envelope'];
            if ($d->envelope->identity_hash !== $c->identity_hash) {
                throw new GraphFailure(401);
            }
            $graph = app(GraphMail::class);
            $items = [];
            if ($d->provider_draft_id) {
                try {
                    $item = $graph->call($c, 'GET', GraphMail::messages($env['target_id']).'/'.rawurlencode($d->provider_draft_id).'?$select=id,isDraft,internetMessageId,from,sender');
                    $items[] = $item;
                } catch (GraphFailure $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                }
            }
            foreach (array_unique([$env['target_id'], ...($c->account_sent_items ? [$env['account_id']] : [])]) as $mailbox) {
                $items = array_merge($items, $graph->correlated($c, $mailbox, $d->dispatch_key));
            }
            $sent = [];
            foreach ($items as $item) {
                if (isset($item['isDraft']) && ! $item['isDraft']) {
                    $sent[$item['id']] = $item;
                }
            }
            if ($sent) {
                $item = reset($sent);
                $expected = $env['sender']['email'];
                $actual = mb_strtolower($item['sender']['emailAddress']['address'] ?? '');
                $note = 'Sent Item observed; delivery and reading remain unconfirmed.';
                if ($actual && $actual !== $expected) {
                    $note .= ' Provider sender differs from configured send mode; Admin must review Exchange rights.';
                }
                MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['status' => 'observed', 'observed_at' => now(), 'internet_id' => $item['internetMessageId'] ?? $d->internet_id, 'last_error' => $actual && $actual !== $expected ? $note : null, 'next_attempt_at' => null]);
                app(MailOutbox::class)->event($d, 'sent_item_observed', $note);
            } else {
                MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['status' => $d->status === 'submitting' ? 'uncertain' : $d->status, 'last_error' => $d->status === 'accepted' ? 'Accepted; Sent Item has not appeared yet. Delivery remains unconfirmed.' : 'No conclusive Sent Item found. The draft does not prove the send failed; do not resend.', 'next_attempt_at' => $d->reconcile_attempts < 10 ? now()->addMinutes(2) : null]);
            }
        } catch (GraphFailure $e) {
            if (in_array($e->status, [401, 403], true)) {
                app(Mailboxes::class)->pause($e->getMessage(), $c);
            } MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['last_error' => $e->getMessage(), 'next_attempt_at' => $d->reconcile_attempts < 10 ? now()->addSeconds(max(120, $e->retryAfter)) : null]);
        } catch (\Throwable $e) {
            MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['last_error' => 'Reconciliation stopped. Historical dispatch is preserved; staff review required.', 'next_attempt_at' => null]);
        } finally {
            MailDispatch::whereKey($d->id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null]);
        }
    }
}
