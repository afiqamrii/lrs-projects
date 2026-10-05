<?php

namespace App\Actions;

use App\Jobs\DispatchMail;
use App\Jobs\ReconcileMail;
use App\Models\CompanySetting;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\User;
use App\Support\Audit;
use App\Support\MailRelease;
use App\Support\Processing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MailOutbox
{
    public function authorize(string $kind, int $id, User $staff, string $digest): MailEnvelope
    {
        return DB::transaction(function () use ($kind, $id, $staff, $digest): MailEnvelope {
            $release = app(MailRelease::class);
            $source = $release->source($kind, $id, $staff, true);
            $c = MailboxConnection::whereKey($release->connection($source)->id)->lockForUpdate()->firstOrFail();
            $snapshot = $release->preview($source, $c);
            $hash = Processing::hash($snapshot);
            if (! hash_equals($digest, $hash)) {
                $release->fail('The final preview changed. Reload and review the exact sender, content and files.');
            }
            $e = MailEnvelope::firstOrCreate(['source_key' => $source['key'], 'identity_hash' => $c->identity_hash, 'digest' => $hash, 'authorized_by' => $staff->id], ['mailbox_connection_id' => $c->id, 'operational_message_approval_id' => $source['operational_message_approval_id'] ?? null, 'followup_stage_id' => $source['followup_stage_id'] ?? null, 'client_quotation_approval_id' => $source['client_quotation_approval_id'] ?? null, 'rfq_approval_id' => $source['rfq_approval_id'], 'clarification_id' => $source['clarification_id'], 'inquiry_id' => $source['inquiry_id'], 'content_digest' => $source['digest'], 'snapshot' => $snapshot, 'authorized_by' => $staff->id, 'authorizer_name' => $staff->name, 'authorized_at' => now()]);
            Audit::record('Exact mailbox envelope authorized', $e, actor: $staff, details: ['authorization' => ['before' => null, 'after' => ['digest' => $e->digest, 'fixture' => $c->is_demo]]]);

            return $e;
        });
    }

    public function enqueue(MailEnvelope $envelope, User $staff, string $key, string $digest): MailDispatch
    {
        return DB::transaction(function () use ($envelope, $staff, $key, $digest): MailDispatch {
            $release = app(MailRelease::class);
            $release->preflight($envelope, $staff, true);
            if (! hash_equals($digest, $envelope->digest)) {
                $release->fail('Confirm the exact authorized envelope before enqueueing.');
            }
            $existing = MailDispatch::where('dispatch_key', $key)->first();
            if ($existing) {
                if ($existing->mail_envelope_id !== $envelope->id) {
                    $release->fail('This action identity belongs to another message. Reload.');
                }

                return $existing;
            }
            if (MailDispatch::where('source_key', $envelope->source_key)->where('status', '!=', 'cancelled')->exists()) {
                $release->fail('This approval already has a dispatch. Inspect its status; do not send again. A deliberate resend needs a newly approved revision.');
            }
            $d = MailDispatch::create(['dispatch_key' => $key, 'mail_envelope_id' => $envelope->id, 'source_key' => $envelope->source_key, 'is_demo' => $envelope->snapshot['envelope']['is_demo'], 'requested_by' => $staff->id, 'requested_at' => now(), 'outbound_epoch' => CompanySetting::current()->outbound_epoch]);
            $this->event($d, 'queued', $envelope->followup_stage_id ? 'Exact reminder covered by a bounded staff activation or per-message approval.' : 'Explicit staff request. Connecting/approving alone never sends.', $staff);
            DispatchMail::dispatch($d->id)->onQueue('mail')->afterCommit();

            return $d;
        });
    }

    public function cancel(MailDispatch $dispatch, User $staff): void
    {
        Gate::forUser($staff)->authorize('update', $dispatch->envelope->inquiry);
        DB::transaction(function () use ($dispatch, $staff): void {
            $d = MailDispatch::whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if ($d->submission_started_at || in_array($d->status, ['accepted', 'observed', 'uncertain'], true)) {
                $d->update(['cancel_requested_at' => now()]);
                $this->event($d, 'late_cancellation', 'Submission started. Cancellation cannot recall this message. Reconcile provider evidence.', $staff);
            } else {
                $d->update(['status' => 'cancelled', 'lease' => null, 'lease_until' => null]);
                $this->event($d, 'cancelled', 'Cancelled before submission. A provider draft may remain unsent; review provider drafts before a new explicit request.', $staff);
            }
        });
    }

    public function recover(MailDispatch $dispatch, User $staff, string $reason): void
    {
        Gate::forUser($staff)->authorize('update', $dispatch->envelope->inquiry);
        DB::transaction(function () use ($dispatch, $staff, $reason): void {
            $d = MailDispatch::whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            if (in_array($d->status, ['accepted', 'uncertain', 'submitting', 'observed'], true) || ($d->submission_started_at && ! ($d->upload_state['send_rejected'] ?? false))) {
                $this->event($d, 'reconciliation_requested', $reason, $staff);
                ReconcileMail::dispatch($d->id)->onQueue('mail')->afterCommit();

                return;
            }
            if ($d->status === 'failed') {
                app(MailRelease::class)->preflight($d->envelope, $staff, true);
                $d->update(['outbound_epoch' => CompanySetting::current()->outbound_epoch, 'status' => $d->provider_draft_id ? 'preparing' : 'queued', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);
                $this->event($d, 'preparation_retry', $reason, $staff);
                DispatchMail::dispatch($d->id)->onQueue('mail')->afterCommit();
            }
        });
    }

    public function event(MailDispatch $dispatch, string $kind, string $note, ?User $staff = null): void
    {
        DB::table('mail_events')->insert(['mail_dispatch_id' => $dispatch->id, 'actor_id' => $staff?->id, 'kind' => $kind, 'note' => $note, 'created_at' => now()]);
        Audit::record('Mailbox dispatch '.$kind, $dispatch, actor: $staff, details: ['mail_state' => ['before' => null, 'after' => ['state' => $kind, 'note' => $note]]], systemActor: $staff ? null : 'System / mailbox');
    }
}
