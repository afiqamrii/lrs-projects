<?php

namespace App\Console\Commands;

use App\Actions\MailOutbox;
use App\Jobs\DispatchMail;
use App\Jobs\ReconcileMail;
use App\Jobs\SyncMailbox;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainMailbox extends Command
{
    protected $signature = 'lrs:mailbox-tick';

    protected $description = 'Enqueue due mailbox work and recover expired claims without blind sends';

    public function handle(): int
    {
        $c = MailboxConnection::current();
        foreach (MailDispatch::whereNotNull('lease_until')->where('lease_until', '<', now())->get() as $candidate) {
            DB::transaction(function () use ($candidate): void {
                $d = MailDispatch::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if (! $d->lease_until?->isPast()) {
                    return;
                }
                $uncertain = $d->status === 'submitting' || ($d->draft_started_at && ! $d->provider_draft_id);
                $d->update(['lease' => null, 'lease_until' => null, 'status' => $uncertain ? 'uncertain' : $d->status, 'last_error' => $uncertain ? 'Worker claim expired at a potentially ambiguous provider operation. Reconciliation required.' : $d->last_error, 'next_attempt_at' => now()]);
                app(MailOutbox::class)->event($d, 'claim_recovered', 'Expired worker claim recovered. No ambiguous submission is automatically retried.');
            });
        }
        if (! $c->usable()) {
            $this->info('Mailbox unavailable or paused. Historical records retained; manual workflows remain available.');

            return self::SUCCESS;
        }
        MailboxFolder::where('identity_hash', $c->identity_hash)->where('enabled', true)->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->each(fn (MailboxFolder $f) => SyncMailbox::dispatch($f->id)->onQueue('mail'));
        MailDispatch::whereIn('status', ['queued', 'preparing', 'ready'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->each(fn (MailDispatch $d) => DispatchMail::dispatch($d->id)->onQueue('mail'));
        MailDispatch::whereIn('status', ['accepted', 'uncertain', 'submitting'])->where('next_attempt_at', '<=', now())->where('reconcile_attempts', '<', 10)->each(fn (MailDispatch $d) => ReconcileMail::dispatch($d->id)->onQueue('mail'));
        $this->info('Due mailbox work enqueued. Claims coalesce duplicate jobs; no real send is authorized by this command.');

        return self::SUCCESS;
    }
}
