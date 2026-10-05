<?php

namespace App\Actions;

use App\Models\AttentionTask;
use App\Models\ClientQuotationApproval;
use App\Models\CompanySetting;
use App\Models\FollowupAuthorization;
use App\Models\FollowupPlan;
use App\Models\FollowupPolicy;
use App\Models\FollowupStage;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\FollowupCalendar;
use App\Support\FollowupEligibility;
use App\Support\GmailMime;
use App\Support\MailRelease;
use App\Support\OutboundControl;
use App\Support\Processing;
use App\Support\QuotationEligibility;
use App\Support\RfqEligibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageFollowups
{
    public static function defaults(string $kind): array
    {
        return ['intervals' => [2, 3], 'max_sends' => 2, 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => [], 'opens' => '09:00', 'closes' => '17:00', 'timezone' => CompanySetting::current()->timezone, 'freshness_minutes' => 15, 'subject' => 'Re: [reference] · Follow-up [stage_number]', 'body' => $kind === 'rfq' ? "Hello [recipient_name],\n\nMay we check whether you can respond to [reference], revision [revision]? The original shipment requirements remain unchanged. Please let us know if you have a question or cannot offer this service.\n\nResponse deadline: [deadline]\n\nThank you,\n[company_name]" : "Hello [recipient_name],\n\nMay we check whether you have had an opportunity to review [reference], revision [revision]? Please let us know if you have a question or would like a revision.\n\nThe original quotation, prices and terms remain unchanged and are valid until [deadline].\n\nThank you,\n[company_name]", 'attachment_mode' => 'none'];
    }

    public function policy(User $staff, array $d): FollowupPolicy
    {
        Gate::forUser($staff)->authorize('manage-company');

        return DB::transaction(function () use ($staff, $d): FollowupPolicy {
            CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $last = FollowupPolicy::latestFor($d['kind']);
            if (($last?->number ?? 0) !== (int) $d['expected_number']) {
                Processing::fail('Policy changed after you opened this page. Reload and compare the current version.');
            }
            $p = array_intersect_key($d, self::defaults($d['kind']));
            $p['intervals'] = array_map('intval', $p['intervals']);
            $p['weekdays'] = array_map('intval', $p['weekdays']);
            $p['max_sends'] = (int) $p['max_sends'];
            $p['freshness_minutes'] = (int) $p['freshness_minutes'];
            $record = FollowupPolicy::create(['kind' => $d['kind'], 'number' => ($last?->number ?? 0) + 1, 'enabled' => $d['enabled'], 'snapshot' => $p, 'digest' => Processing::hash($p), 'approved_by' => $staff->id, 'reason' => $d['reason'], 'created_at' => now()]);
            Audit::record('Company follow-up policy version approved', $record, actor: $staff);
            if (! $record->enabled) {
                foreach (FollowupPlan::where('kind', $d['kind'])->whereIn('state', ['active', 'held'])->orderBy('id')->get() as $plan) {
                    $this->halt($plan, 'paused', 'Company policy disabled by Admin.', $staff);
                }
            }

            return $record;
        });
    }

    public function parent(string $kind, int $id, User $staff, bool $lock = false): array
    {
        abort_unless(in_array($kind, ['rfq', 'client_quote'], true), 404);
        if ($kind === 'rfq') {
            $a = RfqApproval::findOrFail($id);
            $r = $a->revision;
            $i = $r->rfq->inquiry;
            Gate::forUser($staff)->authorize('update', $r->rfq);
            if ($lock) {
                app(ManageRfq::class)->locked($r->rfq, $staff, $r->number);
            }
            RfqEligibility::assert($r, true);
            $content = $a->snapshot;
            $manual = $a->dispatch;
            $expiry = CarbonImmutable::parse($r->payload['response_due_at']);
            $target = 'rfq:'.$r->rfq_id;
        } else {
            $a = ClientQuotationApproval::findOrFail($id);
            $r = $a->revision;
            $i = $r->quotation->inquiry;
            Gate::forUser($staff)->authorize('update', $i);
            if ($lock) {
                app(ManageQuotation::class)->lockRevision($r, $staff);
            }
            QuotationEligibility::approved($a);
            $content = $a->snapshot['content'];
            $expiry = $r->expires_at;
            $target = 'client_quote:'.$r->client_quotation_id;
            $manual = DB::table('quotation_manual_sends')->where('client_quotation_approval_id', $a->id)->first();
        }
        if (! $staff->is_active || ! in_array($staff->role, ['admin', 'agent'], true)) {
            abort(403);
        }

        return compact('a', 'r', 'i', 'content', 'manual', 'expiry', 'target');
    }

    public function preview(string $kind, int $id, User $staff, string $mode, bool $attach = false, bool $ack = false, ?string $manualSentAt = null): array
    {
        $v = $this->parent($kind, $id, $staff);
        $policy = FollowupPolicy::latestFor($kind);
        if (! $policy?->enabled) {
            Processing::fail('Admin must approve and enable this company policy before activation.');
        }
        $p = $policy->snapshot;
        if (! hash_equals($policy->digest, Processing::hash($p))) {
            Processing::fail('Policy integrity failed.');
        }
        $ds = MailDispatch::where('source_key', $kind.':'.$id)->where('status', '!=', 'cancelled')->latest('id')->get();
        if ($ds->contains(fn ($d) => in_array($d->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true))) {
            Processing::fail('Original send is pending or uncertain. Resolve it before activation.');
        }
        $base = $ds->first(fn ($d) => in_array($d->status, ['accepted', 'observed'], true) && ($d->accepted_at || $d->observed_at));
        if (! $base && ! $v['manual']) {
            Processing::fail('Record an actual manual send or wait for known provider acceptance first.');
        }
        $declared = $v['manual'] ? ($manualSentAt ? CarbonImmutable::createFromFormat('!Y-m-d\\TH:i:s', $manualSentAt, CompanySetting::current()->timezone)->utc() : CarbonImmutable::parse($v['manual']->sent_at ?? $v['manual']->recorded_at)) : null;
        if ($declared && ($declared->isFuture() || $declared->lt($v['a']->approved_at->startOfMinute()))) {
            Processing::fail('Declare the actual past manual sending time after this exact approval.');
        }
        $baseline = $base ? ['kind' => 'Provider accepted; delivery unconfirmed', 'sent_at' => ($base->accepted_at ?? $base->observed_at)->toIso8601String(), 'dispatch_id' => $base->id, 'recipients' => [$v['content']['to'], ...$v['content']['cc']]] :
        ['kind' => 'Manually declared communication; no provider evidence', 'sent_at' => $declared->toIso8601String(), 'dispatch_id' => null, 'recipients' => isset($v['manual']->recipients) ? [$v['manual']->recipients['to'], ...$v['manual']->recipients['cc']] : [$v['content']['to'], ...$v['content']['cc']], 'evidence' => $v['manual']->evidence ?? $v['manual']->reason ?? 'Recorded manual communication'];
        $plan = FollowupPlan::where('target_key', $v['target'])->first();
        $count = $plan?->send_count ?? 0;
        if ($count >= $p['max_sends']) {
            Processing::fail('The cumulative cap is exhausted. Policy changes cannot reset it.');
        }
        if ($plan && $plan->stages()->whereIn('state', ['queued', 'uncertain'])->whereHas('envelope.dispatches', fn ($q) => $q->where(fn ($x) => $x->whereNotNull('submission_started_at')->orWhereIn('status', ['submitting', 'uncertain'])))->exists()) {
            Processing::fail('A reminder has unresolved submission evidence. Reconcile before a new activation.');
        }
        $content = $v['content'];
        $content['manifest'] = $attach && $p['attachment_mode'] === 'approved' ? $content['manifest'] : [];
        if ($kind === 'client_quote' && collect($content['manifest'])->contains(fn ($f) => ! isset($f['quotation_revision_id']))) {
            Processing::fail('Customer reminders may contain only the exact approved customer PDF.');
        }
        $c = $base ? $base->envelope->mailbox : app(MailRelease::class)->connection(['expected_envelope' => $kind === 'client_quote' ? $v['a']->snapshot['envelope'] : null]);
        $content['thread_provider_id'] = $base && $base->envelope->identity_hash === $c->identity_hash ? ($c->provider === 'gmail' ? $base->provider_thread_id : $base->provider_draft_id) : null;
        if ($c->provider === 'gmail' && $base) {
            if (! $base->internet_id || ! $base->provider_thread_id) {
                Processing::fail('Reconcile the original Gmail message and thread before activating reminders.');
            }
            $content['in_reply_to'] = $base->internet_id;
            $content['references'] = $base->internet_id;
            $content['original_subject'] = $base->envelope->snapshot['content']['subject'];
        }
        $content['revision'] = $v['r']->number;
        $source = ['key' => 'followup-preview', 'inquiry_id' => $v['i']->id, 'content' => $content, 'digest' => Processing::hash($content)];
        if ($kind === 'client_quote') {
            $source['expected_envelope'] = $v['a']->snapshot['envelope'];
        }
        $actual = app(MailRelease::class)->preview($source, $c);
        $messages = [];
        $dates = [];
        $date = CarbonImmutable::parse($baseline['sent_at']);
        $last = $plan?->stages()->whereNotNull('sent_at')->latest('id')->first();
        if ($last) {
            $date = $last->sent_at;
        }
        for ($n = $count; $n < $p['max_sends']; $n++) {
            $date = FollowupCalendar::next($date, $p['intervals'][$n], $p);
            $dates[] = $date->toIso8601String();
            $values = ['[original_subject]' => $content['original_subject'] ?? $content['subject'], '[reference]' => $content['reference'], '[revision]' => (string) $v['r']->number, '[recipient_name]' => $content['to']['name'], '[company_name]' => $content['company']['name'], '[deadline]' => $v['expiry']->setTimezone($p['timezone'])->format('d M Y, H:i T'), '[stage_number]' => (string) ($n + 1)];
            $messages[(string) ($n + 1)] = array_replace($content, ['subject' => strtr($p['subject'], $values), 'body' => strtr($p['body'], $values)]);
            if ($c->provider === 'gmail' && $base && GmailMime::subject($messages[(string) ($n + 1)]['subject']) !== GmailMime::subject($content['original_subject'])) {
                Processing::fail('Gmail replies must retain the original subject. Ask Admin to use Re: [original_subject] in a new policy, then approve the exact activation.');
            }
        }

        return ['kind' => $kind, 'parent_id' => $id, 'revision_id' => $v['r']->id, 'target_key' => $v['target'], 'inquiry_id' => $v['i']->id, 'policy_id' => $policy->id, 'policy_number' => $policy->number, 'policy' => $p, 'mode' => $mode, 'identity_hash' => $c->identity_hash, 'envelope' => $actual['envelope'], 'baseline' => $baseline, 'expires_at' => $v['expiry']->toIso8601String(), 'content' => $content, 'messages' => $messages, 'dates' => $dates, 'send_count' => $count, 'response_cutoff' => $ack ? (MailMessage::max('id') ?? 0) : 0, 'acknowledge_response' => $ack];
    }

    public function activate(string $kind, int $id, User $staff, array $d): FollowupPlan
    {
        return DB::transaction(function () use ($kind, $id, $staff, $d): FollowupPlan {
            $v = $this->parent($kind, $id, $staff, true);
            $plan = FollowupPlan::firstOrCreate(['target_key' => $v['target']], ['inquiry_id' => $v['i']->id, 'kind' => $kind]);
            $plan = FollowupPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $s = $this->preview($kind, $id, $staff, $d['mode'], ! empty($d['attach']), ! empty($d['acknowledge_response']), $d['manual_sent_at'] ?? null);
            if (! hash_equals($d['digest'], Processing::hash($s))) {
                Processing::fail('The activation preview changed. Reload and approve the exact current preview.');
            }
            if ($s['acknowledge_response']) {
                $pending = MailMessage::where('direction', 'incoming')->where('is_demo', $v['i']->is_demo)->where('received_at', '>=', $s['baseline']['sent_at'])->get()->filter(fn ($m) => $m->inquiry_id === $v['i']->id || in_array($m->sender_email, array_column($s['baseline']['recipients'], 'email'), true) || collect($m->candidates)->contains(fn ($x) => ($x['inquiry_id'] ?? null) === $v['i']->id))->contains(fn ($m) => ! $m->response_reviewed_at && $m->match_state !== 'ignored');
                if ($pending) {
                    Processing::fail('Review all potentially related replies before acknowledging prior response evidence.');
                }
            }
            $this->cancelPending($plan);
            $a = FollowupAuthorization::create(['followup_plan_id' => $plan->id, 'followup_policy_id' => $s['policy_id'], 'rfq_approval_id' => $kind === 'rfq' ? $id : null, 'client_quotation_approval_id' => $kind === 'client_quote' ? $id : null, 'mode' => $s['mode'], 'snapshot' => $s, 'digest' => Processing::hash($s), 'approved_by' => $staff->id, 'reason' => $d['reason'], 'created_at' => now()]);
            if (CarbonImmutable::parse($s['dates'][0])->gte(CarbonImmutable::parse($s['expires_at']))) {
                Processing::fail('No remaining reminder fits within the original validity. The quotation will not be extended.');
            }
            $plan->update(['outbound_epoch' => CompanySetting::current()->outbound_epoch, 'authorization_id' => $a->id, 'state' => 'active', 'next_due_at' => $s['dates'][0], 'reason' => null]);
            $plan = $plan->fresh();
            if ($issue = FollowupEligibility::issue($plan)) {
                Processing::fail($issue[1]);
            }
            Audit::record('Bounded follow-up activation approved', $a, actor: $staff, details: ['scope' => ['before' => null, 'after' => ['mode' => $s['mode'], 'cap' => $s['policy']['max_sends'], 'cumulative' => $plan->send_count, 'baseline' => $s['baseline']['kind']]]]);

            return $plan;
        });
    }

    public function cancelPending(FollowupPlan $plan): void
    {
        foreach ($plan->stages()->whereIn('state', ['needs_review', 'queued', 'uncertain'])->orderBy('id')->lockForUpdate()->get() as $stage) {
            $d = $stage->envelope?->dispatches()->latest('id')->first();
            if ($d && ($d->submission_started_at || in_array($d->status, ['submitting', 'uncertain', 'accepted', 'observed'], true))) {
                $d->update(['cancel_requested_at' => now()]);

                continue;
            }
            if ($d) {
                $d->update(['status' => 'cancelled', 'lease' => null, 'lease_until' => null]);
                app(MailOutbox::class)->event($d, 'cancelled', 'Follow-up authorization stopped before submission.');
            }
            $stage->update(['state' => 'cancelled']);
        }
    }

    public function halt(FollowupPlan $plan, string $state, string $reason, ?User $staff = null, ?int $message = null): void
    {
        DB::transaction(function () use ($plan, $state, $reason, $staff, $message): void {
            $plan = FollowupPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $plan->update(['state' => $state, 'reason' => $reason, 'next_due_at' => null]);
            $this->cancelPending($plan);
            if ($state !== 'cancelled') {
                $this->task($plan, $state, $reason, 'Review the source evidence and resolve the underlying issue. A new exact activation is required to resume.', $message);
            }
            Audit::record('Follow-up '.$state, $plan, actor: $staff, details: ['reason' => ['before' => null, 'after' => $reason]], systemActor: $staff ? null : 'System / follow-ups');
        });
    }

    public function task(FollowupPlan $plan, string $kind, string $title, string $next, ?int $message = null): AttentionTask
    {
        $owner = $plan->inquiry->owner_id ?? $plan->authorization?->approved_by;

        return AttentionTask::firstOrCreate(['dedup_key' => $plan->id.':'.$plan->authorization_id.':'.$kind.':'.($message ?? 0)], ['inquiry_id' => $plan->inquiry_id, 'followup_plan_id' => $plan->id, 'mail_message_id' => $message, 'owner_id' => $owner, 'kind' => $kind, 'title' => mb_substr($title, 0, 255), 'next_action' => $next]);
    }

    public function tick(FollowupPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $a = $plan->authorization;
            if (! $a) {
                return;
            }
            $this->parentLocks($plan);
            $plan = FollowupPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->outcomes($plan);
            $plan = $plan->fresh();
            if (! in_array($plan->state, ['active', 'held'], true)) {
                return;
            }
            try {
                OutboundControl::assertAvailable($plan->outbound_epoch);
            } catch (ValidationException) {
                $this->halt($plan, 'paused', 'Emergency sending pause: approve a new exact activation after Admin resumes.');

                return;
            }
            if ($issue = FollowupEligibility::issue($plan)) {
                $this->halt($plan, $issue[0] === 'stop' ? 'stopped' : 'held', $issue[1], message: $issue[2]);

                return;
            }
            if ($plan->state === 'held') {
                return;
            }
            $p = $plan->authorization->snapshot['policy'];
            if ($plan->stages()->whereIn('state', ['needs_review', 'queued', 'uncertain'])->exists()) {
                return;
            }
            if ($plan->send_count >= $p['max_sends']) {
                $this->halt($plan, 'exhausted', 'No response after approved follow-ups');

                return;
            }
            if (! $plan->next_due_at || $plan->clock()->lt($plan->next_due_at)) {
                return;
            }
            if (! FollowupCalendar::window($plan->clock(), $p)) {
                $plan->update(['next_due_at' => FollowupCalendar::allowed($plan->clock(), $p)]);

                return;
            }
            if ($plan->stages()->whereIn('state', ['needs_review', 'queued', 'uncertain'])->exists()) {
                return;
            }
            $a = $plan->authorization;
            $content = $a->snapshot['messages'][(string) ($plan->send_count + 1)];
            $stage = FollowupStage::create(['followup_plan_id' => $plan->id, 'followup_authorization_id' => $a->id, 'sequence' => ($plan->stages()->max('sequence') ?? 0) + 1, 'ordinal' => $plan->send_count + 1, 'due_at' => $plan->next_due_at, 'content' => $content, 'digest' => Processing::hash($content), 'state' => $a->mode === 'automatic' ? 'queued' : 'needs_review']);
            if ($a->mode === 'automatic') {
                $this->enqueue($stage, User::findOrFail($a->approved_by));
            } else {
                $this->task($plan, 'manual_review', 'Follow-up '.$stage->ordinal.' is ready for review', 'Open the exact message and approve this one follow-up.');
            }
        });
    }

    public function parentLocks(FollowupPlan $plan): void
    {
        CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
        Inquiry::whereKey($plan->inquiry_id)->lockForUpdate()->firstOrFail();
        $s = $plan->authorization->snapshot;
        $staff = User::findOrFail($plan->authorization->approved_by);
        if ($staff->is_active) {
            try {
                $this->parent($plan->kind, $s['parent_id'], $staff, true);
            } catch (ValidationException) {
            }
        }

        FollowupPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
    }

    public function source(FollowupStage $stage, User $staff, bool $lock = false): array
    {
        $plan = $stage->plan;
        if ($lock) {
            $this->parentLocks($plan);
            $stage = FollowupStage::whereKey($stage->id)->lockForUpdate()->firstOrFail();
            $plan = $plan->fresh();
        }
        Gate::forUser($staff)->authorize('update', $plan->inquiry);
        if ($plan->state !== 'active' || $plan->authorization_id !== $stage->followup_authorization_id || ! in_array($stage->state, ['queued', 'needs_review'], true)) {
            Processing::fail('This follow-up is stopped, replaced or no longer eligible.');
        }
        if ($stage->authorization->mode === 'manual_review' && (! $stage->reviewed_by || ! User::whereKey($stage->reviewed_by)->where('is_active', true)->exists())) {
            Processing::fail('This exact follow-up requires explicit message approval.');
        }
        if (! hash_equals($stage->digest, Processing::hash($stage->authorization->snapshot['messages'][(string) $stage->ordinal] ?? []))) {
            Processing::fail('This message is outside the exact approved activation.');
        }
        if (! hash_equals($stage->digest, Processing::hash($stage->content))) {
            Processing::fail('Follow-up message integrity failed.');
        }
        if ($issue = FollowupEligibility::issue($plan, true)) {
            Processing::fail($issue[1]);
        }
        $cap = $stage->authorization->snapshot['policy']['max_sends'];
        if (! $stage->counted_at && $plan->send_count >= $cap) {
            Processing::fail('The cumulative approved send cap is exhausted.');
        }
        if ($plan->clock()->lt($stage->due_at)) {
            Processing::fail('This reminder is not due.');
        }

        return ['key' => 'followup:'.$stage->id, 'inquiry_id' => $plan->inquiry_id, 'rfq_approval_id' => null, 'clarification_id' => null, 'client_quotation_approval_id' => null, 'followup_stage_id' => $stage->id, 'content' => $stage->content, 'digest' => $stage->digest, 'expected_envelope' => $stage->authorization->snapshot['envelope']];
    }

    public function enqueue(FollowupStage $stage, User $staff): void
    {
        $release = app(MailRelease::class);
        $source = $this->source($stage, $staff);
        $s = $release->preview($source, $release->connection($source));
        $outbox = app(MailOutbox::class);
        $e = $outbox->authorize('followup', $stage->id, $staff, Processing::hash($s));
        $outbox->enqueue($e, $staff, (string) Str::uuid(), $e->digest);
    }

    public function approveStage(FollowupStage $stage, User $staff, string $digest): void
    {
        DB::transaction(function () use ($stage, $staff, $digest): void {
            $this->parentLocks($stage->plan);
            $stage = FollowupStage::whereKey($stage->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($staff)->authorize('update', $stage->plan->inquiry);
            if ($stage->state !== 'needs_review' || $stage->digest !== $digest) {
                Processing::fail('The draft is changed or already reviewed. Reload.');
            }
            $stage->update(['reviewed_by' => $staff->id, 'reviewed_at' => now(), 'state' => 'queued']);
            $this->enqueue($stage, $staff);
            Audit::record('Exact manual-review reminder approved', $stage, actor: $staff);
        });
    }

    public function beforeSubmission(MailDispatch $d): void
    {
        if (! $d->envelope->followup_stage_id) {
            return;
        }
        $stage = FollowupStage::whereKey($d->envelope->followup_stage_id)->lockForUpdate()->firstOrFail();
        $this->source($stage, User::findOrFail($d->requested_by));
        if (! $stage->counted_at) {
            $stage->update(['counted_at' => $stage->plan->clock()]);
            $stage->plan->increment('send_count');
        }
    }

    public function outcomes(FollowupPlan $plan): void
    {
        foreach ($plan->stages()->whereIn('state', ['queued', 'uncertain'])->orderBy('id')->get() as $stage) {
            $d = $stage->envelope?->dispatches()->latest('id')->first();
            if (! $d) {
                continue;
            }
            if (in_array($d->status, ['accepted', 'observed'], true)) {
                $sent = $d->accepted_at ?? $d->observed_at;
                $stage->update(['state' => 'accepted', 'sent_at' => $sent]);
                if ($plan->state === 'active') {
                    $p = $stage->authorization->snapshot['policy'];
                    $next = $plan->send_count < $p['max_sends'] ? FollowupCalendar::next($sent, $p['intervals'][$plan->send_count], $p) : null;
                    $plan->update(['next_due_at' => $next]);
                }
            } elseif (in_array($d->status, ['uncertain', 'submitting'], true)) {
                $stage->update(['state' => 'uncertain']);
                $this->task($plan, 'uncertain', 'Reminder submission is uncertain', 'Reconcile the existing dispatch; never create a replacement send.');
            } elseif ($d->status === 'failed') {
                $this->halt($plan, 'held', $d->last_error ?? 'Reminder preparation failed.');
            } elseif ($d->status === 'cancelled') {
                $stage->update(['state' => 'cancelled']);
            }
        }
    }

    public function response(MailMessage $message): void
    {
        if ($message->direction !== 'incoming') {
            return;
        }
        foreach (FollowupPlan::whereIn('state', ['active', 'held'])->orderBy('id')->get() as $plan) {
            if ($issue = FollowupEligibility::issue($plan)) {
                if ($issue[2]) {
                    $this->halt($plan, $issue[0] === 'stop' ? 'stopped' : 'held', $issue[1], message: $issue[2]);
                }
            }
        }
    }
}
