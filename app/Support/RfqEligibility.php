<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\Rfq;
use App\Models\RfqRevision;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RfqEligibility
{
    public static function readiness(Inquiry $inquiry): array
    {
        $reasons = array_values($inquiry->gaps());
        if (! $inquiry->eligible()) {
            $reasons[] = 'Confirm the current shipment as Ready for sourcing before preparing or releasing an RFQ.';
        }

        return array_values(array_unique($reasons));
    }

    public static function limitations(Rfq $rfq): array
    {
        $services = $rfq->vendor->services;
        $mode = $rfq->round->version->snapshot['shipment']['mode'];

        return $services && ! in_array($mode, $services, true) ? [$mode.' is absent from this vendor’s recorded services. Confirm its ability or choose another vendor.'] : [];
    }

    public static function reasons(RfqRevision $revision, bool $release = false): array
    {
        $rfq = $revision->rfq->fresh();
        $p = $revision->payload;
        if (! $p['subject'] || preg_match('/[\r\n]/', $p['subject']) || ! $p['opening'] || ! $p['closing']) {
            $reasonsText = 'Provide a safe subject and nonempty professional opening/closing.';
        }
        $reasons = self::readiness($rfq->inquiry);
        if (isset($reasonsText)) {
            $reasons[] = $reasonsText;
        }
        if ($revision->status === 'changes_requested') {
            $reasons[] = 'Changes were requested. Save a revised draft before approval.';
        }
        if ($rfq->round->version->number !== $rfq->inquiry->shipment_revision || $rfq->round->version->snapshot_hash !== $rfq->inquiry->snapshotHash()) {
            $reasons[] = 'This sourcing round uses an earlier shipment. Reconfirm the inquiry and select vendors for its current version.';
        }
        if ($rfq->current_number !== $revision->number) {
            $reasons[] = 'This revision was superseded. Open the current revision and compare the changes.';
        }
        if ($release && (! $revision->approval || $revision->status !== 'approved')) {
            $reasons[] = 'Approve this exact current revision before using its outputs.';
        }
        if (in_array($revision->status, ['cancelled', 'superseded'], true)) {
            $reasons[] = 'This request is '.$revision->status.'. Prepare a new revision for future use.';
        }
        if (! $rfq->vendor->is_active) {
            $reasons[] = 'The vendor is inactive. Choose an active vendor or reactivate its directory record.';
        }
        $recipients = array_merge($p['to'] ? [$p['to']] : [], $p['cc']);
        if (! $p['to']) {
            $reasons[] = 'Select one primary To contact.';
        }
        foreach ($recipients as $recipient) {
            $contact = Contact::find($recipient['id']);
            if (! $contact || $contact->vendor_id !== $rfq->vendor_id || ! $contact->is_active || ! filter_var($contact->email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $contact->email)) {
                $reasons[] = 'A selected recipient is inactive, unavailable or does not belong to this vendor.';
            } elseif ($contact->email !== $recipient['email'] || $contact->name !== $recipient['name']) {
                $reasons[] = 'A selected contact changed. Save a new revision with the reviewed current recipient.';
            }
        }
        if (self::limitations($rfq) && ! $p['limitation_reason']) {
            $reasons[] = 'Explain why the recorded capability limitation is acceptable, or choose another vendor.';
        }
        foreach (Rfq::where('sourcing_round_id', $rfq->sourcing_round_id)->where('id', '!=', $rfq->id)->get() as $other) {
            $otherRevision = $other->current();
            if ($otherRevision->status === 'cancelled') {
                continue;
            }
            $otherP = $otherRevision->payload;
            $otherEmails = array_column(array_merge($otherP['to'] ? [$otherP['to']] : [], $otherP['cc']), 'email');
            if (array_intersect(array_column($recipients, 'email'), $otherEmails) && ! $p['shared_email_reason']) {
                $reasons[] = 'A selected email is shared with another selected vendor. Choose a different contact, cancel the duplicate request, or document the deliberate separate-company resolution.';
            }
            $text = mb_strtolower(implode(' ', array_filter([$p['subject'], $p['opening'], $p['closing'], $p['vendor_notes'], $p['alternative_notes']])));
            foreach (array_filter([$other->vendor->company_name, $other->vendor->display_name, ...$otherEmails]) as $identity) {
                if (mb_strlen($identity) > 3 && str_contains($text, mb_strtolower($identity))) {
                    $reasons[] = 'Remove another selected vendor’s identity from this separate request.';
                    break;
                }
            }
        }
        if (! $p['response_due_at']) {
            $reasons[] = 'Select a vendor response deadline.';
        } else {
            $due = CarbonImmutable::parse($p['response_due_at']);
            if ($due->isPast()) {
                $reasons[] = 'The response deadline has passed. Prepare a revised deadline before release.';
            }
            if ($rfq->inquiry->response_due_at && $due->greaterThanOrEqualTo($rfq->inquiry->response_due_at) && (! $p['deadline_reason'] || ($p['deadline_ack_client_due_at'] ?? null) !== $rfq->inquiry->response_due_at->toIso8601String())) {
                $reasons[] = 'The vendor deadline leaves no time before the client deadline. Record the agreed exception or choose an earlier time.';
            }
        }
        if (! $p['company']['reply_name'] || ! filter_var($p['company']['reply_email'], FILTER_VALIDATE_EMAIL) || ! $p['company']['signature']) {
            $reasons[] = 'Admin must configure the company reply name, email and signature. Then refresh them in a new draft revision.';
        }
        if (! $p['attachments_reviewed']) {
            $reasons[] = 'Review and acknowledge the attachment/disclosure selection, including an intentional choice of no attachments.';
        }
        if (($p['manifest'] || $p['disclose_identity']) && ! $p['disclosure_notes']) {
            $reasons[] = 'Explain the necessary client/document disclosure before approval.';
        }
        $s = $rfq->round->version->snapshot['shipment'];
        if (($s['pickup_address'] || $s['delivery_address']) && ! $p['disclose_addresses']) {
            $reasons[] = 'Explicitly approve the logistics address disclosure required by the confirmed service.';
        }
        try {
            $current = RfqContent::manifest($rfq, array_column($p['manifest'], 'document_id'));
            if (Processing::hash($current) !== Processing::hash($p['manifest'])) {
                $reasons[] = 'A selected attachment version or metadata changed. Review it and save a new revision.';
            }
        } catch (ValidationException $e) {
            $reasons[] = implode(' ', array_merge(...array_values($e->errors())));
        }
        if (array_sum(array_column($p['manifest'], 'size')) > config('rfq.preparation_limit_kb') * 1024) {
            $reasons[] = 'The selected files exceed the configured preparation limit. Prepare smaller reviewed copies or reduce the selection; the future provider limit remains unverified.';
        }
        if ($release && $revision->approval && Processing::hash($revision->approval->snapshot) !== $revision->approval->digest) {
            $reasons[] = 'Approval evidence failed its integrity check. Contact Admin.';
        }

        return array_values(array_unique($reasons));
    }

    public static function assert(RfqRevision $revision, bool $release = false): void
    {
        if ($reasons = self::reasons($revision, $release)) {
            throw ValidationException::withMessages(['eligibility' => implode(' ', $reasons)]);
        }
    }

    public static function automaticDispatchAllowed(RfqRevision $revision): bool
    {
        return self::reasons($revision, true) === [] && ! $revision->approval->dispatch && ! MailDispatch::where('source_key', 'rfq:'.$revision->approval->id)->where('status', '!=', 'cancelled')->exists();
    }
}
