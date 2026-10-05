<?php

namespace App\Support;

use App\Actions\ManageFollowups;
use App\Models\ClientDecision;
use App\Models\CompanySetting;
use App\Models\FollowupPlan;
use App\Models\FollowupPolicy;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FollowupEligibility
{
    public static function issue(FollowupPlan $plan, bool $window = false): ?array
    {
        $a = $plan->authorization;
        if (! $a) {
            return ['stop', 'Missing authorization.', null];
        }
        $s = $a->snapshot;
        if ($plan->kind === 'client_quote' && ClientDecision::where('client_quotation_revision_id', $s['revision_id'])->exists()) {
            return ['stop', 'A staff-reviewed client decision/question exists for this exact quotation. Prepare a renewed quotation before follow-ups.', null];
        }
        $p = $s['policy'];
        if (CompanySetting::current()->timezone !== $p['timezone']) {
            return ['hold', 'Company timezone changed. Review a new policy and exact activation.', null];
        }
        if (! hash_equals($a->digest, Processing::hash($s)) || ! hash_equals($a->policy->digest, Processing::hash($a->policy->snapshot))) {
            return ['stop', 'Approval integrity failed.', null];
        }
        if (! FollowupPolicy::latestFor($plan->kind)?->enabled) {
            return ['hold', 'Company follow-up policy is disabled.', null];
        }
        if (! User::whereKey($a->approved_by)->where('is_active', true)->whereIn('role', ['admin', 'agent'])->exists()) {
            return ['stop', 'The follow-up authorizer is inactive.', null];
        }
        $c = MailboxConnection::where('identity_hash', $s['identity_hash'])->first();
        if (! $c || ! $c->usable() || ! $c->incoming_enabled || $c->identity_hash !== $s['identity_hash']) {
            return ['hold', 'Mailbox disconnected, authorization revoked or sender identity changed.', null];
        }
        if ($plan->clock()->gte(CarbonImmutable::parse($s['expires_at']))) {
            return ['stop', 'The approved request or quotation has expired.', null];
        }
        try {
            app(ManageFollowups::class)->parent($plan->kind, $s['parent_id'], User::findOrFail($a->approved_by));
        } catch (ValidationException $e) {
            return ['stop', implode(' ', array_merge(...array_values($e->errors()))), null];
        }
        foreach (self::related($plan) as $m) {
            if ($m->match_state === 'duplicate_copy' || $m->match_state === 'ignored' || ($m->response_reviewed_at && $m->classification === 'noise')) {
                continue;
            }
            $exact = $plan->kind === 'rfq' ? $m->rfq_revision_id === $s['revision_id'] : $m->client_quotation_revision_id === $s['revision_id'];
            $candidate = collect($m->candidates)->contains(fn ($v) => ($v['rfq_revision_id'] ?? null) === $s['revision_id'] && $plan->kind === 'rfq' || ($v['client_quotation_revision_id'] ?? null) === $s['revision_id'] && $plan->kind === 'client_quote');
            if ($m->classification === 'bounce' && ($exact || $candidate)) {
                return ['stop', 'Delivery failed. Review the approved contact; no substitute address will be selected.', $m->id];
            }
            if ($m->id <= $s['response_cutoff']) {
                continue;
            }
            if ($m->classification === 'out_of_office' && ($exact || $candidate)) {
                return ['hold', 'Out-of-office evidence requires staff review.', $m->id];
            }
            if ($exact && in_array($m->classification, ['quote', 'question', 'decline', 'acceptance', 'revision_request'], true) && ($plan->kind === 'rfq' || $m->response_reviewed_at)) {
                return ['stop', MailMessage::CLASSES[$m->classification].' received for the exact approved revision.', $m->id];
            }

            return ['hold', $m->attachment_state !== 'complete' ? 'A potentially related reply is still being imported. Review its original evidence.' : 'A potentially related reply requires exact revision and classification review.', $m->id];
        }
        $incoming = MailboxConnection::where('incoming_enabled', true)->get();
        foreach ($incoming as $connection) {
            if (! $connection->usable() || ! $connection->folders()->where('identity_hash', $connection->identity_hash)->where('enabled', true)->where('kind', 'incoming')->exists()) {
                return ['hold', 'A selected incoming connection is unavailable or has no active incoming folder. Review sync before follow-ups.', null];
            }
        }
        $identities = $incoming->pluck('identity_hash');
        $folders = MailboxFolder::where('enabled', true)->where('kind', 'incoming')->whereIn('identity_hash', $identities)->get();
        if ($folders->isEmpty()) {
            return ['hold', 'Select and sync an incoming folder before follow-ups.', null];
        }
        foreach ($folders as $f) {
            if (! $f->last_sync_at || $f->last_sync_at->lt(now()->subMinutes($p['freshness_minutes'])) || $f->page || $f->lease_until?->isFuture() || $f->cycle_count || $f->last_error || $f->failure_count) {
                return ['hold', 'Incoming sync is incomplete, unhealthy or older than '.$p['freshness_minutes'].' minutes.', null];
            }
        }
        $pending = MailDispatch::whereHas('envelope', fn ($q) => $q->whereHas('followupStage', fn ($x) => $x->where('followup_plan_id', $plan->id)))->whereIn('status', ['uncertain', 'submitting'])->exists();
        if ($pending) {
            return ['hold', 'A previous reminder has an uncertain or in-flight submission. Reconcile it before continuing.', null];
        }
        if ($window && ! FollowupCalendar::window($plan->clock(), $p)) {
            return ['hold', 'Outside the approved business-day sending window.', null];
        }

        return null;
    }

    public static function related(FollowupPlan $plan): Collection
    {
        $s = $plan->authorization->snapshot;
        $emails = array_column(array_merge([$s['content']['to']], $s['content']['cc']), 'email');

        return MailMessage::where('direction', 'incoming')->where('is_demo', $plan->inquiry->is_demo)->where('received_at', '>=', $s['baseline']['sent_at'])->orderBy('id')->get()->filter(fn ($m) => ($m->inquiry_id === $plan->inquiry_id && ($plan->kind === 'rfq' ? ! $m->client_quotation_revision_id && (! $m->rfq_revision_id || $m->rfq_revision_id === $s['revision_id']) : ! $m->rfq_revision_id)) || in_array($m->sender_email, $emails, true) || collect($m->candidates)->contains(fn ($x) => ($x['inquiry_id'] ?? null) === $plan->inquiry_id));
    }
}
