<?php

namespace App\Support;

use App\Models\ClientQuotationApproval;
use App\Models\ClientQuotationRevision;
use App\Models\MailDispatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class QuotationEligibility
{
    public static function digest(ClientQuotationRevision $r): string
    {
        return Processing::hash(['schema' => 'lrs-quotation-revision-1', 'quotation_id' => $r->client_quotation_id, 'number' => $r->number,
            'selection_id' => $r->offer_selection_id, 'source' => $r->source_snapshot, 'payload' => $r->payload, 'pricing' => $r->pricing,
            'pdf' => ['path' => $r->pdf_path, 'sha256' => $r->pdf_checksum, 'size' => $r->pdf_size], 'state' => $r->state,
            'expires_at' => $r->expires_at?->toIso8601String(), 'resend_of_id' => $r->resend_of_id, 'change_reason' => $r->change_reason, 'created_by' => $r->created_by, 'author_name' => $r->author_name, 'created_at' => $r->created_at->toIso8601String()]);
    }

    public static function reasons(ClientQuotationRevision $r): array
    {
        $r = $r->fresh();
        $p = $r->payload;
        $reasons = OfferEligibility::selectionReasons($r->selection);
        if ($r->number !== $r->quotation->current_number) {
            $reasons[] = 'A newer client quotation revision supersedes this one.';
        }
        if (! hash_equals($r->digest, self::digest($r)) || Processing::hash($r->source_snapshot) !== Processing::hash($r->selection->snapshot)) {
            $reasons[] = 'Quotation snapshot integrity failed.';
        }
        $reasons = array_merge($reasons, $r->pricing['gaps']);
        if ($r->state !== 'needs_review') {
            $reasons[] = 'Save for review before final approval.';
        }
        if (! $r->expires_at || $r->expires_at->isPast()) {
            $reasons[] = 'Client quotation validity is missing or expired.';
        } elseif ($r->expires_at->greaterThan(CarbonImmutable::parse($r->source_snapshot['commercial']['valid_until']))) {
            $reasons[] = 'Client validity extends beyond the selected vendor rate.';
        }
        if (! empty($p['issue_date']) && ($p['issue_date'] > now($p['company']['timezone'])->toDateString() || ($r->expires_at && $p['issue_date'] > $r->expires_at->setTimezone($p['company']['timezone'])->toDateString()))) {
            $reasons[] = 'Issue date must be today or earlier and no later than expiry.';
        }
        if (empty($p['terms_confirmed']) || empty($p['conditions']) || empty($p['inclusions']) || empty($p['exclusions'])) {
            $reasons[] = 'Review and confirm inclusions, exclusions and conditions.';
        }
        if (empty($p['subject']) || empty($p['body'])) {
            $reasons[] = 'Complete the editable customer email.';
        }
        $client = $r->quotation->inquiry->client;
        if (! $client?->is_active) {
            $reasons[] = 'Client identity is unavailable or inactive.';
        }
        foreach (array_merge($p['to'] ? [$p['to']] : [], $p['cc']) as $recipient) {
            if (! $client?->contacts()->whereKey($recipient['id'])->where('is_active', true)->where('email', $recipient['email'])->exists()) {
                $reasons[] = 'A recipient changed or is inactive; create a new revision with authorized client contacts.';
            }
        }
        if (empty($p['to'])) {
            $reasons[] = 'Choose an active contact belonging to this client.';
        }
        if (! filter_var($p['company']['reply_email'], FILTER_VALIDATE_EMAIL) || empty($p['company']['reply_name'])) {
            $reasons[] = 'Configure the company reply contact and save a new revision.';
        }
        try {
            QuotationPdf::bytes($r);
        } catch (ValidationException $e) {
            $reasons = array_merge($reasons, array_merge(...array_values($e->errors())));
        }
        if ($r->resend_of_id) {
            $d = MailDispatch::find($r->resend_of_id);
            if (! $d || in_array($d->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true)) {
                $reasons[] = 'Prior dispatch is pending or uncertain. Reconcile before a deliberate resend.';
            }
        }
        $prior = MailDispatch::whereHas('envelope', fn ($q) => $q->where('inquiry_id', $r->quotation->inquiry_id)->whereNotNull('client_quotation_approval_id')->whereHas('clientQuotationApproval', fn ($a) => $a->where('client_quotation_revision_id', '!=', $r->id)))->latest('id')->get();
        if ($prior->contains(fn ($d) => in_array($d->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true))) {
            $reasons[] = 'An earlier quotation dispatch is pending or uncertain. Cancel or reconcile it before release.';
        }
        $sent = $prior->first(fn ($d) => in_array($d->status, ['accepted', 'observed'], true));
        if ($sent && ! $prior->contains(fn ($d) => $d->id === $r->resend_of_id && in_array($d->status, ['accepted', 'observed', 'failed'], true))) {
            $reasons[] = 'Authorize a deliberate resend linked to an earlier resolved dispatch before approval.';
        }

        return array_values(array_unique($reasons));
    }

    public static function assert(ClientQuotationRevision $r): void
    {
        if ($reasons = self::reasons($r)) {
            Processing::fail(implode(' ', $reasons));
        }
    }

    public static function approved(ClientQuotationApproval $a): void
    {
        self::assert($a->revision);
        if (! hash_equals($a->digest, Processing::hash($a->snapshot)) || ($a->snapshot['revision_digest'] ?? null) !== $a->revision->digest || ! User::whereKey($a->approved_by)->where('is_active', true)->exists()) {
            Processing::fail('The exact approval is invalid or its reviewer is inactive.');
        }
    }
}
