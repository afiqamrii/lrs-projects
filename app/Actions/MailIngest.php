<?php

namespace App\Actions;

use App\Jobs\ImportMailAttachments;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxFolder;
use App\Models\MailDispatch;
use App\Models\MailMessage;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\Processing;
use App\Support\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MailIngest
{
    public function handle(MailboxFolder $folder, array $source): ?MailMessage
    {
        $id = $source['id'] ?? null;
        if (! is_string($id) || strlen($id) > 512) {
            throw new \RuntimeException('Missing bounded provider identity');
        }

        return DB::transaction(function () use ($folder, $source, $id): ?MailMessage {
            $key = $folder->mailboxKey();
            $message = MailMessage::where('mailbox_key', $key)->where('provider_id', $id)->lockForUpdate()->first();
            if (isset($source['@removed'])) {
                if ($message) {
                    DB::table('mail_folder_message')->where('mailbox_folder_id', $folder->id)->where('mail_message_id', $message->id)->update(['removed_at' => now()]);
                    $message->update(['deleted_at_provider' => now()]);
                }

                return $message;
            }
            if (! $message) {
                $date = isset($source['receivedDateTime']) ? CarbonImmutable::parse($source['receivedDateTime']) : now()->toImmutable();
                if ($date->lt($folder->import_from)) {
                    return null;
                }
                if (strlen(json_encode($source, JSON_THROW_ON_ERROR)) > config('mailbox.message_max_bytes')) {
                    throw new \RuntimeException('Message evidence exceeds configured body limit');
                }
                $sender = mb_strtolower($source['from']['emailAddress']['address'] ?? $source['sender']['emailAddress']['address'] ?? '');
                $message = MailMessage::create(['mailbox_key' => $key, 'provider_id' => $id, 'is_demo' => $folder->mailbox->is_demo, 'direction' => ($folder->kind === 'sent' || in_array($sender, [$folder->mailbox->target_email, $folder->mailbox->account_email], true)) ? 'outgoing' : 'incoming', 'internet_id' => mb_substr($source['internetMessageId'] ?? '', 0, 998) ?: null, 'sender_email' => mb_substr($sender, 0, 254), 'subject' => mb_substr($source['subject'] ?? '(No subject)', 0, 500), 'received_at' => $date, 'source' => $source, 'source_hash' => Processing::hash($source), 'classification' => 'other', 'match_state' => 'unmatched']);
                if ($message->direction === 'outgoing') {
                    $message->update(['classification' => 'other', 'match_state' => 'sent_evidence', 'attachment_state' => 'not_imported']);
                    $this->sentEvidence($message);
                } else {
                    $this->match($message);
                    ImportMailAttachments::dispatch($message->id, $folder->id)->onQueue('mail')->afterCommit();
                }
                Audit::record('Original Outlook message ingested', $message, details: ['source' => ['before' => null, 'after' => ['fixture' => $message->is_demo, 'digest' => $message->source_hash]]], systemActor: 'System / mailbox');
            } else {
                if ($folder->kind === 'sent') {
                    $this->sentEvidence($message);
                } elseif ($message->direction === 'incoming' && $message->attachment_state !== 'complete') {
                    ImportMailAttachments::dispatch($message->id, $folder->id)->onQueue('mail')->afterCommit();
                }
            }
            DB::table('mail_folder_message')->upsert([['mailbox_folder_id' => $folder->id, 'mail_message_id' => $message->id, 'removed_at' => null]], ['mailbox_folder_id', 'mail_message_id'], ['removed_at']);

            return $message;
        });
    }

    private function headers(MailMessage $m): array
    {
        $headers = [];
        foreach ($m->source['internetMessageHeaders'] ?? [] as $h) {
            $headers[strtolower($h['name'])] = $h['value'];
        }

        return $headers;
    }

    private function classification(MailMessage $m): string
    {
        $h = $this->headers($m);
        $subject = mb_strtolower($m->subject);
        $text = mb_strtolower(mb_substr($m->text(), 0, 1500));
        if (preg_match('/undeliver|delivery.*fail|returned mail|failure notice/', $subject) || str_contains($h['content-type'] ?? '', 'delivery-status')) {
            return 'bounce';
        }
        if (preg_match('/out of office|automatic reply|auto.?reply|away from office/', $subject) || (isset($h['x-autoreply']) || isset($h['x-autorespond']))) {
            return 'out_of_office';
        }
        if (isset($h['list-id']) || (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no')) {
            return 'noise';
        }
        if (preg_match('/unable to quote|decline|cannot offer|no service/', $subject.' '.$text)) {
            return 'decline';
        }
        if (preg_match('/quotation|quote attached|our rates/', $subject.' '.$text)) {
            return 'quote';
        }
        if (str_contains($text, '?') || str_contains($subject, 'question')) {
            return 'question';
        }

        return 'other';
    }

    private function match(MailMessage $m): void
    {
        $classification = $this->classification($m);
        $h = $this->headers($m);
        preg_match_all('/<[^<>\s]+>/', $h['in-reply-to'] ?? '', $reply);
        preg_match_all('/<[^<>\s]+>/', $h['references'] ?? '', $refs);
        $ids = array_unique(array_merge($reply[0], $refs[0]));
        $correlation = $h['x-lrs-dispatch-id'] ?? null;
        $dispatches = MailDispatch::with('envelope.approval.revision.rfq', 'envelope.inquiry')->where('is_demo', $m->is_demo)->whereIn('status', ['accepted', 'observed', 'uncertain'])->where(function ($q) use ($ids, $correlation): void {
            $q->whereIn('internet_id', $ids);
            if ($correlation && Str::isUuid($correlation)) {
                $q->orWhere('dispatch_key', $correlation);
            }
        })->get();
        $candidates = [];
        foreach ($dispatches as $d) {
            if (! $this->sameMailbox($d, $m) || $d->envelope->inquiry->is_demo !== $m->is_demo) {
                continue;
            }
            $s = $d->envelope->snapshot['content'];
            $known = in_array($m->sender_email, array_column(array_merge([$s['to']], $s['cc']), 'email'), true);
            $candidates[$d->source_key] = ['inquiry_id' => $d->envelope->inquiry_id, 'rfq_revision_id' => $d->envelope->approval?->rfq_revision_id, 'known_sender' => $known, 'reason' => $known ? 'Stored outgoing thread identity and approved recipient' : 'Known outgoing thread; unfamiliar sender requires assessment'];
        }
        if (! $candidates) {
            $approvals = RfqApproval::with('revision.rfq')->whereHas('revision.rfq', fn ($q) => $q->whereRaw('? LIKE \'%\' || reference || \'%\'', [$m->subject]))->get();
            foreach ($approvals as $approval) {
                $sent = $approval->revision->rfq->inquiry->is_demo === $m->is_demo && ($approval->dispatch || MailDispatch::where('source_key', 'rfq:'.$approval->id)->where('is_demo', $m->is_demo)->whereIn('status', ['accepted', 'observed', 'uncertain'])->get()->contains(fn ($d) => $this->sameMailbox($d, $m)));
                if (! $sent) {
                    continue;
                }
                $s = $approval->snapshot;
                $known = in_array($m->sender_email, array_column(array_merge([$s['to']], $s['cc']), 'email'), true);
                $candidates['rfq:'.$approval->id] = ['inquiry_id' => $s['inquiry_id'], 'rfq_revision_id' => $approval->rfq_revision_id, 'known_sender' => $known, 'reason' => $known ? 'Exact RFQ reference and approved recipient; revision requires review when ambiguous' : 'RFQ reference but unfamiliar sender'];
            }
        }
        $m->fill(['classification' => $classification, 'candidates' => array_values($candidates)]);
        if (count($candidates) === 1 && reset($candidates)['known_sender'] && ! in_array($classification, ['noise', 'out_of_office', 'bounce'], true)) {
            $candidate = reset($candidates);
            $this->associate($m, $candidate['inquiry_id'], $candidate['rfq_revision_id'], $candidate['reason']);

            return;
        }
        if ($candidates) {
            $m->fill(['match_state' => 'unmatched', 'match_reason' => 'Review the thread, sender and exact request revision. Automated evidence never counts as a commercial response.']);
        } elseif (in_array($classification, ['noise', 'out_of_office', 'bounce'], true)) {
            $m->fill(['match_state' => 'automated', 'match_reason' => 'Automated evidence retained. No inquiry or reply loop created.']);
        } elseif ($ids || preg_match('/^\s*(re|fw|fwd):/i', $m->subject) || DB::table('contacts')->where('email', $m->sender_email)->exists() || ! filter_var($m->sender_email, FILTER_VALIDATE_EMAIL)) {
            $m->fill(['match_state' => 'unmatched', 'match_reason' => 'Reply or known vendor without sufficient thread evidence. Subject/sender alone cannot select a case.']);
        } else {
            $company = CompanySetting::current();
            $i = Inquiry::create(['reference' => InquiryWorkflow::reference(), 'title' => mb_substr($m->subject, 0, 255), 'source_channel' => 'email', 'is_demo' => $m->is_demo, 'status' => 'needs_review', 'priority' => 'normal', 'received_at' => $m->received_at, 'shipment' => Shipment::normalize([], true), 'shipment_revision' => 1, 'lock_version' => 0, 'public_contact' => ['name' => $m->source['from']['emailAddress']['name'] ?? $m->sender_email, 'email' => $m->sender_email, 'company' => null, 'phone' => null], 'owner_id' => $company->public_intake_owner_id && User::whereKey($company->public_intake_owner_id)->where('is_active', true)->exists() ? $company->public_intake_owner_id : null, 'original_source_text' => $m->text()]);
            $this->associate($m, $i->id, null, 'New incoming request. Client/contact identity awaits staff assessment.');
            Audit::record('Email inquiry received', $i, systemActor: 'System / mailbox');

            return;
        }
        $m->save();
    }

    private function associate(MailMessage $m, int $inquiryId, ?int $revisionId, string $reason, bool $preserveClassification = false): void
    {
        $m->fill(['inquiry_id' => $inquiryId, 'rfq_revision_id' => $revisionId, 'match_state' => 'matched', 'match_reason' => $reason]);
        if (! $preserveClassification && ! $revisionId && ! in_array($m->classification, ['bounce', 'out_of_office', 'noise'], true)) {
            $m->classification = 'customer';
        }
        $m->save();
        $i = Inquiry::whereKey($inquiryId)->lockForUpdate()->firstOrFail();
        if (! $revisionId && ! in_array($m->classification, ['bounce', 'out_of_office', 'noise'], true) && $i->status === 'needs_client_information') {
            $before = Audit::snapshot($i);
            $i->update(['status' => 'needs_review', 'lock_version' => $i->lock_version + 1]);
            Audit::record('Customer email returned inquiry to review', $i, $before, systemActor: 'System / mailbox');
        }
    }

    private function sentEvidence(MailMessage $m): void
    {
        $h = $this->headers($m);
        $key = $h['x-lrs-dispatch-id'] ?? null;
        foreach ($m->source['singleValueExtendedProperties'] ?? [] as $property) {
            if ($property['id'] === config('mailbox.extended_property')) {
                $key = $property['value'];
            }
        }
        $d = MailDispatch::where(function ($q) use ($key, $m): void {
            $q->whereRaw('FALSE');
            if ($key && Str::isUuid($key)) {
                $q->orWhere('dispatch_key', $key);
            } if ($m->internet_id) {
                $q->orWhere('internet_id', $m->internet_id);
            }
        })->whereIn('status', ['accepted', 'uncertain', 'submitting', 'observed'])->first();
        if ($d && empty($m->source['isDraft']) && $this->sameMailbox($d, $m)) {
            $d->update(['status' => 'observed', 'observed_at' => now(), 'internet_id' => $m->internet_id, 'last_error' => null, 'next_attempt_at' => null]);
            $m->update(['inquiry_id' => $d->envelope->inquiry_id, 'rfq_revision_id' => $d->envelope->approval?->rfq_revision_id]);
        }
    }

    private function sameMailbox(MailDispatch $d, MailMessage $m): bool
    {
        $e = $d->envelope->snapshot['envelope'];
        $parts = explode(':', $m->mailbox_key);

        return $d->is_demo === $m->is_demo && ($e['tenant_id'] ?? null) === ($parts[0] ?? null) && in_array($parts[1] ?? null, [$e['target_id'] ?? null, $e['account_id'] ?? null], true);
    }

    public function review(MailMessage $message, User $staff, array $data): void
    {
        abort_unless($message->direction === 'incoming', 422);
        DB::transaction(function () use ($message, $staff, $data): void {
            $m = MailMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($m->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Another staff member reviewed this message. Reload before changing its match.']);
            }
            $old = ['inquiry_id' => $m->inquiry_id, 'rfq_revision_id' => $m->rfq_revision_id, 'classification' => $m->classification];
            $m->classification = $data['classification'];
            if ($data['decision'] === 'associate') {
                $i = Inquiry::findOrFail($data['inquiry_id']);
                Gate::forUser($staff)->authorize('update', $i);
                if ($i->is_demo !== $m->is_demo) {
                    throw ValidationException::withMessages(['inquiry_id' => 'Keep synthetic fixture evidence and real inquiry records separate. Choose a case with the same data provenance.']);
                }
                $revisionId = empty($data['rfq_revision_id']) ? null : (int) $data['rfq_revision_id'];
                if ($revisionId && ! RfqApproval::where('rfq_revision_id', $revisionId)->whereHas('revision.rfq', fn ($q) => $q->where('inquiry_id', $i->id))->exists()) {
                    throw ValidationException::withMessages(['rfq_revision_id' => 'Choose an approved RFQ revision belonging to this inquiry.']);
                }
                $this->associate($m, $i->id, $revisionId, $data['reason'], true);
            } elseif ($data['decision'] === 'new') {
                $i = Inquiry::create(['reference' => InquiryWorkflow::reference(), 'title' => mb_substr($m->subject, 0, 255), 'source_channel' => 'email', 'is_demo' => $m->is_demo, 'status' => 'needs_review', 'priority' => 'normal', 'received_at' => $m->received_at, 'shipment' => Shipment::normalize([], true), 'shipment_revision' => 1, 'lock_version' => 0, 'public_contact' => ['name' => $m->source['from']['emailAddress']['name'] ?? $m->sender_email, 'email' => $m->sender_email, 'company' => null, 'phone' => null], 'original_source_text' => $m->text(), 'owner_id' => $staff->id]);
                $this->associate($m, $i->id, null, $data['reason'], true);
            } else {
                $m->fill(['inquiry_id' => null, 'rfq_revision_id' => null, 'match_state' => $data['decision'] === 'ignore' ? 'ignored' : 'unmatched', 'match_reason' => $data['reason']]);
            }
            $m->lock_version++;
            $m->save();
            DB::table('mail_events')->insert(['mail_message_id' => $m->id, 'actor_id' => $staff->id, 'kind' => 'staff_review', 'note' => $data['reason'], 'created_at' => now()]);
            Audit::record('Mail classification and match reviewed', $m, actor: $staff, details: ['match' => ['before' => $old, 'after' => ['inquiry_id' => $m->inquiry_id, 'rfq_revision_id' => $m->rfq_revision_id, 'classification' => $m->classification, 'reason' => $data['reason']]]]);
            ImportMailAttachments::dispatch($m->id, DB::table('mail_folder_message')->where('mail_message_id', $m->id)->value('mailbox_folder_id'))->onQueue('mail')->afterCommit();
        });
    }
}
