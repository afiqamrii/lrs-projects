<?php

namespace App\Support;

use App\Actions\ManageRfq;
use App\Models\Clarification;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\MailEnvelope;
use App\Models\RfqApproval;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MailRelease
{
    public function source(string $kind, int $id, User $staff, bool $lock = false): array
    {
        if (! $staff->is_active || ! in_array($staff->role, ['admin', 'agent'], true)) {
            abort(403);
        }
        if ($kind === 'rfq') {
            $approval = RfqApproval::findOrFail($id);
            $rfq = $approval->revision->rfq;
            Gate::forUser($staff)->authorize('update', $rfq);
            if ($lock) {
                app(ManageRfq::class)->locked($rfq, $staff, $approval->revision->number);
            }
            RfqEligibility::assert($approval->revision, true);
            if ($approval->dispatch) {
                $this->fail('This exact approval was manually recorded as sent. Prepare and approve a deliberate new revision.');
            }

            return ['key' => 'rfq:'.$id, 'inquiry_id' => $rfq->inquiry_id, 'rfq_approval_id' => $id, 'clarification_id' => null, 'content' => $approval->snapshot, 'digest' => $approval->digest];
        }
        abort_unless($kind === 'clarification', 404);
        $item = Clarification::findOrFail($id);
        Gate::forUser($staff)->authorize('update', $item->inquiry);
        if ($lock) {
            CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            Inquiry::whereKey($item->inquiry_id)->lockForUpdate()->firstOrFail();
            $item = Clarification::whereKey($id)->lockForUpdate()->firstOrFail();
        }
        if ($item->status !== 'approved' || ! $item->currentFor($item->inquiry) || in_array($item->inquiry->status, ['on_hold', 'closed', 'ready_for_sourcing'], true) || ! $item->inquiry->owner?->is_active) {
            $this->fail('Use an exact approved, current clarification for an active inquiry awaiting review/information.');
        }
        $company = RfqContent::company();
        if (! filter_var($company['reply_email'], FILTER_VALIDATE_EMAIL) || ! $company['reply_name']) {
            $this->fail('Admin must configure the company reply contact before authorizing Outlook.');
        }
        $content = ['reference' => 'Clarification '.$item->inquiry->reference, 'revision' => $item->shipment_revision, 'inquiry_id' => $item->inquiry_id, 'vendor_name' => 'Client clarification', 'to' => ['name' => $item->inquiry->contact->name, 'email' => $item->recipient_email], 'cc' => [], 'subject' => $item->inquiry->reference.' · Shipment clarification', 'body' => $item->body, 'manifest' => [], 'company' => $company];

        return ['key' => 'clarification:'.$id, 'inquiry_id' => $item->inquiry_id, 'rfq_approval_id' => null, 'clarification_id' => $id, 'content' => $content, 'digest' => Processing::hash([$item->id, $item->body, $item->recipient_email, $item->shipment_hash, $item->approved_by, $item->approved_at?->toIso8601String()])];
    }

    public function preview(array $source, MailboxConnection $c): array
    {
        if (! $c->usable()) {
            $this->fail('Connect or resume the pinned Outlook mailbox before authorizing release. Manual workflows remain available.');
        }
        if (Inquiry::findOrFail($source['inquiry_id'])->is_demo !== $c->is_demo) {
            $this->fail('Keep synthetic fixture inquiries on fixture transport and real inquiries on the connected company mailbox. Review a case with matching data provenance.');
        }
        $s = $source['content'];
        $envelope = $c->envelope($s['company']['reply_email'], $s['company']['reply_name']);
        if (! $c->transport_limit || ! $c->rights_confirmed) {
            $this->fail('Admin must record verified mailbox send rights and a tenant transport size limit.');
        }
        $size = strlen($s['body']) + strlen($s['subject']) + 8192;
        foreach ($s['manifest'] as $file) {
            if ($file['size'] >= 3000000 && $c->mailbox_type === 'shared') {
                $this->fail('Microsoft documents a large-attachment issue for shared/delegated mailboxes. Each file must be below 3 MB. A smaller prepared file requires a new approved manifest.');
            }
            $size += (int) ceil($file['size'] / 3) * 4 + 2048;
        }
        if ($size > $c->transport_limit) {
            $this->fail('Estimated encoded message size exceeds the configured verified tenant limit. Reduce files through a new reviewed approval. Recipient limits may be lower.');
        }

        return ['content' => $s, 'envelope' => $envelope, 'content_digest' => $source['digest'], 'source_key' => $source['key'], 'estimated_bytes' => $size];
    }

    public function preflight(MailEnvelope $envelope, User $staff, bool $lock = false): array
    {
        $s = $this->source($envelope->rfq_approval_id ? 'rfq' : 'clarification', $envelope->rfq_approval_id ?? $envelope->clarification_id, $staff, $lock);
        $c = MailboxConnection::current();
        if ($lock) {
            $c = MailboxConnection::whereKey(1)->lockForUpdate()->firstOrFail();
        }
        $snapshot = $this->preview($s, $c);
        if ($envelope->identity_hash !== $c->identity_hash || $envelope->content_digest !== $s['digest'] || ! hash_equals($envelope->digest, Processing::hash($snapshot)) || ! hash_equals(Processing::hash($envelope->snapshot), $envelope->digest)) {
            $this->fail('Content or mailbox envelope changed. Review and authorize the current exact preview again.');
        }
        if (! User::whereKey($envelope->authorized_by)->where('is_active', true)->exists()) {
            $this->fail('The release authorizer is inactive. An active staff member must authorize release.');
        }

        return $snapshot;
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['release' => $message]);
    }
}
