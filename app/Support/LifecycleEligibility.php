<?php

namespace App\Support;

use App\Models\BookingHandoff;
use App\Models\ClientDecision;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationRevision;
use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\HandoffPolicy;
use App\Models\HandoffRevision;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\User;
use App\Models\VendorConfirmation;
use App\Models\VendorReconfirmation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LifecycleEligibility
{
    public static function staff(User $staff): void
    {
        abort_unless($staff->fresh()?->is_active && in_array($staff->role, ['admin', 'agent'], true), 403);
    }

    public static function quote(Inquiry $i): ?ClientQuotationRevision
    {
        return ClientQuotation::where('inquiry_id', $i->id)->first()?->current();
    }

    public static function decision(ClientQuotationRevision $q): ?ClientDecision
    {
        return ClientDecision::where('client_quotation_revision_id', $q->id)->orderByDesc('number')->first();
    }

    public static function outcome(ClientQuotationRevision $q): string
    {
        $q = $q->fresh();
        if ($q->number !== $q->quotation->current_number) {
            return 'Superseded';
        }
        if ($q->expires_at?->isPast()) {
            return 'Expired';
        }

        return match (self::decision($q)?->outcome) {
            'accepted' => 'Accepted','revision_requested' => 'Revision requested','declined' => 'Declined','question','review_required' => 'Needs response review',default => 'Awaiting decision'
        };
    }

    public static function documents(Inquiry $i, array $ids): array
    {
        $out = [];
        $disk = Storage::disk('inquiry_documents');
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $d = $i->documents()->whereKey($id)->where('is_archived', false)->first();
            if (! $d || ! $disk->exists($d->storage_path)) {
                Processing::fail('A selected private document is unavailable or belongs to another inquiry.');
            }
            $bytes = $disk->get($d->storage_path);
            if (! hash_equals($d->checksum, hash('sha256', $bytes)) || strlen($bytes) !== $d->size) {
                Processing::fail('A selected document checksum changed. Restore the original or review a new document.');
            }
            $out[] = ['id' => $d->id, 'name' => $d->original_name, 'classification' => $d->classification, 'checksum' => $d->checksum, 'size' => $d->size, 'mime' => $d->mime, 'version' => $d->version, 'scan_status' => $d->scan_status];
        }

        return $out;
    }

    public static function acceptanceReasons(ClientDecision $d): array
    {
        $q = $d->revision;
        $reasons = array_merge(QuotationEligibility::reasons($q), self::replacementReasons($q));
        if ($d->outcome !== 'accepted' || self::decision($q)?->id !== $d->id) {
            $reasons[] = 'The latest decision for this exact quotation is not an unconditional acceptance.';
        }
        if (! $q->approval) {
            $reasons[] = 'The exact quotation has not been approved.';
        } elseif (! hash_equals($q->approval->digest, Processing::hash($q->approval->snapshot)) || ! User::whereKey($q->approval->approved_by)->where('is_active', true)->exists()) {
            $reasons[] = 'Quotation approval integrity or active authority changed.';
        }
        if (! hash_equals($d->digest, Processing::hash($d->snapshot)) || ($d->snapshot['quotation_digest'] ?? null) !== $q->digest) {
            $reasons[] = 'Acceptance evidence integrity failed.';
        }
        $contact = $q->quotation->inquiry->client?->contacts()->whereKey($d->client_contact_id)->where('is_active', true)->first();
        if (! $contact || $contact->email !== ($d->snapshot['contact']['email'] ?? null) || ! User::whereKey($d->reviewed_by)->where('is_active', true)->exists()) {
            $reasons[] = 'Acceptance contact or reviewer is unavailable or changed.';
        }
        try {
            if (self::documents($d->inquiry, array_column($d->snapshot['documents'] ?? [], 'id')) !== ($d->snapshot['documents'] ?? [])) {
                $reasons[] = 'Acceptance document identity changed.';
            }
        } catch (ValidationException $e) {
            $reasons[] = implode(' ', array_merge(...array_values($e->errors())));
        }
        if ($d->mail_message_id) {
            $m = $d->message;
            if (! $m || $m->inquiry_id !== $d->inquiry_id || $m->client_quotation_revision_id !== $q->id || ! $m->response_reviewed_at || $m->match_state !== 'matched' || $m->sender_email !== ($d->snapshot['contact']['email'] ?? null) || $m->source_hash !== ($d->snapshot['source']['source_hash'] ?? null) || $m->lock_version !== ($d->snapshot['source']['lock_version'] ?? null)) {
                $reasons[] = 'The acceptance source no longer has a reviewed exact quotation match.';
            }
        }
        if (MailMessage::where('inquiry_id', $d->inquiry_id)->where('client_quotation_revision_id', $q->id)->where('direction', 'incoming')->where('created_at', '>', $d->created_at)->whereNotIn('match_state', ['ignored', 'duplicate_copy'])->whereNotIn('classification', ['out_of_office', 'bounce', 'automated', 'noise'])->exists()) {
            $reasons[] = 'A newer client reply needs a new decision review before release.';
        }

        return array_values(array_unique($reasons));
    }

    public static function replacementReasons(ClientQuotationRevision $q): array
    {
        $change = VendorConfirmation::where('inquiry_id', $q->quotation->inquiry_id)->where('status', 'changed')->latest('id')->first();
        if ($change && (! $q->selection->revision->reviewed_at?->gt($change->created_at) || ! $q->selection->selected_at->gt($change->created_at))) {
            return ['A material vendor change requires a newly reviewed vendor offer and cost selection before replacement client acceptance.'];
        }

        return [];
    }

    public static function requestReasons(VendorReconfirmation $r): array
    {
        $reasons = self::acceptanceReasons($r->decision);
        if ($r->confirmations()->where('status', 'changed')->exists()) {
            $reasons[] = 'Material vendor changes were recorded after this acceptance. Review the changed offer, create a replacement client quotation and obtain a renewed decision.';
        }
        if ($r->decision->revision->offer_selection_id !== $r->offer_selection_id || $r->selection->snapshot['shipment_version_id'] !== $r->shipment_version_id) {
            $reasons[] = 'The accepted vendor cost or shipment basis changed.';
        }

        return $reasons;
    }

    public static function readiness(Inquiry $i, array $evidence = []): array
    {
        $i = $i->fresh();
        $q = self::quote($i);
        $d = $q ? self::decision($q) : null;
        $r = $d ? VendorReconfirmation::where('client_decision_id', $d->id)->first() : null;
        $v = $r?->current();
        $p = HandoffPolicy::current();
        $policy = $p?->snapshot ?? [];
        $hard = $d ? self::acceptanceReasons($d) : ['Record unconditional acceptance of the current approved quotation.'];
        if (! $p || ! hash_equals($p->digest, Processing::hash($policy)) || ! User::whereKey($p->approved_by)->where('is_active', true)->where('role', 'admin')->exists()) {
            $hard[] = 'An active Admin must approve the company handoff requirements.';
        }
        $vendor = [];
        if (! $v || $v->status !== 'confirmed') {
            $vendor[] = 'Review an exact vendor rate, scope, dates and capacity confirmation.';
        } else {
            $vendor = self::requestReasons($r);
            if (! hash_equals($v->digest, Processing::hash($v->snapshot)) || ! User::whereKey($v->reviewed_by)->where('is_active', true)->exists()) {
                $vendor[] = 'Vendor review integrity or reviewer authority changed.';
            }
            if ($v->expires_at?->isPast()) {
                $vendor[] = 'The vendor reconfirmation expired.';
            }
            if (! empty($policy['freshness_hours']) && $v->confirmed_at->addHours((int) $policy['freshness_hours'])->lte(now())) {
                $vendor[] = 'Vendor reconfirmation exceeds the approved freshness limit.';
            }
            $contact = $r->selection->revision->offer->vendor->contacts()->whereKey($v->contact_id)->where('is_active', true)->first();
            if (! $contact || $contact->email !== ($v->snapshot['contact']['email'] ?? null)) {
                $vendor[] = 'Vendor confirmation contact changed or became inactive.';
            }
            if ($v->mail_message_id && (! $v->message?->response_reviewed_at || $v->message->match_state !== 'matched' || $v->message->operational_message_id !== ($v->snapshot['source']['operational_message_id'] ?? null) || $v->message->source_hash !== ($v->snapshot['source']['source_hash'] ?? null) || $v->message->lock_version !== ($v->snapshot['source']['lock_version'] ?? null))) {
                $vendor[] = 'Vendor mail association changed; review the actual source again.';
            }
            try {
                if (self::documents($i, array_column($v->snapshot['documents'] ?? [], 'id')) !== ($v->snapshot['documents'] ?? [])) {
                    $vendor[] = 'Vendor evidence documents changed.';
                }
            } catch (ValidationException $ex) {
                $vendor[] = implode(' ', array_merge(...array_values($ex->errors())));
            }
            if (MailMessage::where('inquiry_id', $i->id)->where('direction', 'incoming')->where('created_at', '>', $v->created_at)->whereHas('operationalMessage', fn ($m) => $m->where('vendor_reconfirmation_id', $r->id))->whereNotIn('match_state', ['ignored', 'duplicate_copy'])->whereNotIn('classification', ['out_of_office', 'bounce', 'automated', 'noise'])->exists()) {
                $vendor[] = 'A newer vendor response needs another confirmation review.';
            }
        }
        $hard = array_values(array_unique(array_merge($hard, $vendor)));
        $s = $q?->source_snapshot['shipment']['shipment'] ?? $i->shipment;
        $items = [];
        $item = function (string $key, string $label, bool $applies, bool $complete, string $reason, bool $exceptionPermitted = true) use (&$items, $policy, $evidence): void {
            $exception = $evidence['exceptions'][$key] ?? null;
            $allowed = $exceptionPermitted && in_array($key, $policy['operational_exceptions'] ?? [], true) && ! empty($evidence['exception_authority']) && User::whereKey($evidence['exception_authority'])->where('is_active', true)->where('role', 'admin')->exists();
            $items[$key] = ['label' => $label, 'state' => ! $applies ? 'not_applicable' : ($complete ? 'complete' : ($allowed && $exception ? 'conditional' : 'missing')), 'reason' => $reason, 'exception' => $allowed && $exception ? $exception : null];
        };
        $item('commercial', 'Accepted quote, shipment and vendor agreement', true, ! $hard, implode(' ', $hard) ?: 'Exact accepted commercial basis and reviewed capacity.');
        foreach (['pickup' => ['door_to_port', 'door_to_door'], 'delivery' => ['port_to_door', 'door_to_door']] as $type => $scopes) {
            $applies = in_array($s['scope'] ?? 'unknown', $scopes, true) || in_array($type, $s['services'] ?? [], true);
            $item($type.'_address', ucfirst($type).' address', $applies, ! empty($s[$type.'_address']), $s[$type.'_address'] ?? 'Complete and reconfirm the shipment address before release.');
            $item($type.'_contact', ucfirst($type).' contact', $applies, (bool) preg_match('/(?:[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|\+?[0-9][0-9 ()\-]{6,})/i', $evidence[$type.'_contact'] ?? ''), $evidence[$type.'_contact'] ?? 'Name and usable contact details are required for this service.');
        }
        $item('cargo', 'Cargo availability on agreed ready date', true, ! empty($evidence['cargo_ready_confirmed']) && ! empty($evidence['cargo_evidence']) && ! empty($s['cargo_ready_date']), ($s['cargo_ready_date'] ?? 'No agreed date').' · '.($evidence['cargo_evidence'] ?? 'Confirm cargo availability.'));
        $owner = User::whereKey($evidence['operations_owner_id'] ?? 0)->where('is_active', true)->whereIn('role', ['admin', 'agent'])->first();
        $item('owner', 'Responsible operations owner', true, (bool) $owner, $owner?->name ?? 'Assign an active operations owner.');
        $docs = [];
        $docIssue = null;
        try {
            $docs = self::documents($i, $evidence['document_ids'] ?? []);
        } catch (ValidationException $ex) {
            $docIssue = implode(' ', array_merge(...array_values($ex->errors())));
        }
        $required = array_filter($policy['required_documents'] ?? [], fn ($x) => $x['service'] === 'any' || in_array($x['service'], $s['services'] ?? [], true) || (! empty($items[$x['service'].'_address']) && $items[$x['service'].'_address']['state'] !== 'not_applicable'));
        $mapped = $evidence['document_map'] ?? [];
        $docIds = array_column($docs, 'id');
        $missing = [];
        foreach ($required as $index => $requirement) {
            if (! isset($mapped[$index]) || ! in_array((int) $mapped[$index], $docIds, true)) {
                $missing[] = $requirement['label'];
            }
        }
        $item('documents', 'Company-required shipment documents', (bool) $required || (bool) $docIssue, ! $missing && ! $docIssue, $docIssue ?: ($missing ? 'Missing: '.implode(', ', $missing) : 'Selected exact private document versions satisfy the applicable company list.'), ! $docIssue);
        $po = ! empty($d?->snapshot['reference']) || ! empty($d?->snapshot['po_document_id']);
        $item('po', 'Client reference / PO', ! empty($policy['require_po']), $po, $d?->snapshot['reference'] ?? 'Attach the supplied PO in the client decision or record its reference.');
        $deposit = ! empty($evidence['deposit_confirmed']) && ! empty($evidence['deposit_evidence']) && ! empty($evidence['deposit_at']) && strtotime($evidence['deposit_at']) <= now()->timestamp;
        $item('deposit', 'Manually evidenced deposit', ! empty($policy['require_deposit']), $deposit, $evidence['deposit_evidence'] ?? 'Record actual confirmation, date and evidence if required.');
        $blockers = array_values(array_map(fn ($x) => $x['label'].': '.$x['reason'], array_filter($items, fn ($x) => $x['state'] === 'missing')));
        // Age checks are evaluated separately. No moving clock enters an approval fingerprint.
        $deps = ['inquiry' => $i->id, 'generation' => (int) $i->lifecycle_generation, 'status' => $i->status,
            'quotation' => [$q?->id, $q?->digest], 'decision' => [$d?->id, $d?->digest], 'selection' => [$r?->offer_selection_id, $r?->selection->snapshot],
            'shipment' => [$r?->shipment_version_id, $i->snapshotHash()], 'confirmation' => [$v?->id, $v?->digest],
            'policy' => [$p?->id, $p?->digest], 'owner' => $owner?->only(['id', 'name', 'email', 'is_active']), 'evidence' => $evidence, 'documents' => $docs];

        return ['quote' => $q, 'decision' => $d, 'request' => $r, 'confirmation' => $v, 'policy' => $p, 'items' => $items, 'blockers' => $blockers, 'ready' => ! $blockers, 'dependencies' => $deps, 'dependency_digest' => Processing::hash($deps)];
    }

    public static function revisionReasons(HandoffRevision $r): array
    {
        $now = self::readiness($r->handoff->inquiry, $r->snapshot['evidence']);
        $reasons = $now['blockers'];
        if ($r->handoff->current_number !== $r->number || ! hash_equals($r->dependency_digest, $now['dependency_digest'])) {
            $reasons[] = 'The frozen handoff is superseded or its source, checklist or company policy changed. Save a new revision.';
        }
        if (! hash_equals($r->digest, Processing::hash($r->snapshot))) {
            $reasons[] = 'Handoff snapshot integrity failed.';
        }
        try {
            HandoffPdf::bytes($r);
        } catch (ValidationException $e) {
            $reasons[] = implode(' ', array_merge(...array_values($e->errors())));
        }

        return array_unique($reasons);
    }

    public static function approvalReasons(HandoffApproval $a): array
    {
        $reasons = self::revisionReasons($a->revision);
        if (! hash_equals($a->digest, Processing::hash($a->snapshot)) || $a->snapshot['revision_digest'] !== $a->revision->digest || ! User::whereKey($a->approved_by)->where('is_active', true)->exists()) {
            $reasons[] = 'Handoff approval authority or integrity changed.';
        }
        try {
            HandoffPdf::bytes($a);
        } catch (ValidationException $error) {
            $reasons[] = implode(' ', array_merge(...array_values($error->errors())));
        }

        return array_values(array_unique($reasons));
    }

    public static function approved(HandoffApproval $a): void
    {
        $reasons = self::approvalReasons($a);
        if ($reasons) {
            Processing::fail(implode(' ', $reasons));
        }
    }

    public static function statuses(Inquiry $i): array
    {
        $q = self::quote($i);
        $d = $q ? self::decision($q) : null;
        $r = $d ? VendorReconfirmation::where('client_decision_id', $d->id)->first() : null;
        $h = BookingHandoff::where('inquiry_id', $i->id)->first()?->current();
        $approval = $h?->approval;
        $valid = $approval && ! self::approvalReasons($approval);
        $events = $approval ? HandoffEvent::where('handoff_approval_id', $approval->id)->get() : collect();
        $booking = $events->contains('kind', 'booking_confirmed') ? 'Booking confirmed' : ($events->contains('kind', 'booking_requested') ? 'Booking requested' : 'Booking unconfirmed');
        if ($approval && MailDispatch::whereHas('envelope.operationalMessageApproval.message', fn ($m) => $m->where('kind', 'booking')->where('handoff_revision_id', $h->id))->whereIn('status', ['submitting', 'uncertain', 'accepted', 'observed'])->exists() && $booking === 'Booking unconfirmed') {
            $booking = 'Booking requested';
        }
        $handoff = $valid ? ($events->contains('kind', 'handed_to_operations') ? 'Handed to operations' : 'Handoff approved') : ($approval ? 'Release on hold' : ($h && ! self::revisionReasons($h) ? 'Handoff ready' : 'Handoff incomplete'));
        $next = ! $d || $d->outcome !== 'accepted' ? 'Review the current client decision' : (! $r?->current() || $r->current()->status !== 'confirmed' ? 'Review vendor rate and capacity' : (! $h || self::revisionReasons($h) || ($approval && ! $valid) ? 'Complete the readiness checklist' : (! $approval ? 'Approve the frozen handoff' : (! $events->contains('kind', 'handed_to_operations') ? 'Hand the approved record to operations' : ($booking === 'Booking unconfirmed' ? 'Record a booking request or review actual vendor booking evidence' : ($booking === 'Booking requested' ? 'Review vendor booking evidence' : 'Booking evidence recorded'))))));

        return compact('q', 'd', 'r', 'h', 'approval', 'booking', 'handoff', 'next');
    }
}
