<?php

namespace App\Console\Commands;

use App\Actions\MailOutbox;
use App\Jobs\DispatchMail;
use App\Jobs\ReconcileMail;
use App\Jobs\SyncMailbox;
use App\Models\CompanySetting;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainMailbox extends Command
{
    protected $signature = 'lrs:mailbox-tick';

    protected $description = 'Enqueue due mailbox work and recover expired claims without blind sends';

    public function handle(): int
    {
        if (config('operations.restore_lockdown')) {
            $this->warn('Restore lockdown: no mailbox jobs scheduled.');

            return self::SUCCESS;
        }
        $limit = config('operations.tick_limit');
        foreach (MailDispatch::whereNotNull('lease_until')->where('lease_until', '<', now())->orderBy('id')->limit($limit)->get() as $candidate) {
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
        foreach (MailboxConnection::all()->filter(fn ($c) => $c->usable()) as $c) {
            if ($c->incoming_enabled) {
                $c->folders()->where('identity_hash', $c->identity_hash)->where('enabled', true)->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->get()->each(fn ($f) => SyncMailbox::dispatch($f->id)->onQueue('mail'));
            }
            MailDispatch::whereHas('envelope', fn ($q) => $q->where('mailbox_connection_id', $c->id)->where('identity_hash', $c->identity_hash))->where('outbound_epoch', CompanySetting::current()->outbound_epoch)->when(CompanySetting::current()->outbound_paused, fn ($q) => $q->whereRaw('false'))->whereIn('status', ['queued', 'preparing', 'ready'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->get()->each(fn ($d) => DispatchMail::dispatch($d->id)->onQueue('mail'));
            MailDispatch::whereHas('envelope', fn ($q) => $q->where('mailbox_connection_id', $c->id)->where('identity_hash', $c->identity_hash))->whereIn('status', ['accepted', 'uncertain', 'submitting'])->where('next_attempt_at', '<=', now())->where('reconcile_attempts', '<', 10)->orderBy('id')->limit($limit)->get()->each(fn ($d) => ReconcileMail::dispatch($d->id)->onQueue('mail'));
        }
        $this->info('Due work for available authorized connections enqueued. Claims coalesce duplicate jobs.');

        return self::SUCCESS;
    }
}
