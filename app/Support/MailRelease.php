<?php

namespace App\Support;

use App\Actions\ManageFollowups;
use App\Actions\ManageOperationalMail;
use App\Actions\ManageQuotation;
use App\Actions\ManageRfq;
use App\Models\Clarification;
use App\Models\ClientQuotationApproval;
use App\Models\CompanySetting;
use App\Models\FollowupStage;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\OperationalMessageApproval;
use App\Models\RfqApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MailRelease
{
    public function source(string $kind, int $id, User $staff, bool $lock = false): array
    {
        if (! $staff->is_active || ! in_array($staff->role, ['admin', 'agent'], true)) {
            abort(403);
        }
        if ($kind === 'operations') {
            return app(ManageOperationalMail::class)->source(OperationalMessageApproval::findOrFail($id), $staff, $lock);
        }
        if ($kind === 'followup') {
            return app(ManageFollowups::class)->source(FollowupStage::findOrFail($id), $staff, $lock);
        }
        if ($kind === 'client_quote') {
            $approval = ClientQuotationApproval::findOrFail($id);
            $revision = $approval->revision;
            Gate::forUser($staff)->authorize('update', $revision->quotation->inquiry);
            if ($lock) {
                app(ManageQuotation::class)->lockRevision($revision, $staff);
            }
            QuotationEligibility::approved($approval);
            if (DB::table('quotation_manual_sends')->where('client_quotation_approval_id', $id)->exists()) {
                $this->fail('This quotation approval was manually recorded as sent. Prepare a deliberate new revision.');
            }
            $prior = MailDispatch::whereHas('envelope', fn ($q) => $q->where('inquiry_id', $revision->quotation->inquiry_id)->whereNotNull('client_quotation_approval_id')->where('client_quotation_approval_id', '!=', $id))->latest('id')->get();
            if ($prior->contains(fn ($d) => in_array($d->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true))) {
                $this->fail('An earlier quotation dispatch is pending or uncertain. Cancel or reconcile it before releasing another revision.');
            }
            $sent = $prior->first(fn ($d) => in_array($d->status, ['accepted', 'observed'], true));
            if ($sent && ! $prior->contains(fn ($d) => $d->id === $revision->resend_of_id && in_array($d->status, ['accepted', 'observed', 'failed'], true))) {
                $this->fail('A previous quotation reached provider acceptance. Create an explicitly confirmed resend revision linked to its dispatch.');
            }

            return ['key' => 'client_quote:'.$id, 'inquiry_id' => $revision->quotation->inquiry_id, 'rfq_approval_id' => null, 'clarification_id' => null, 'client_quotation_approval_id' => $id, 'content' => $approval->snapshot['content'], 'digest' => $approval->digest, 'expected_envelope' => $approval->snapshot['envelope']];
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
            $this->fail('Admin must configure the company reply contact before authorizing email.');
        }
        $content = ['reference' => 'Clarification '.$item->inquiry->reference, 'revision' => $item->shipment_revision, 'inquiry_id' => $item->inquiry_id, 'vendor_name' => 'Client clarification', 'to' => ['name' => $item->inquiry->contact->name, 'email' => $item->recipient_email], 'cc' => [], 'subject' => $item->inquiry->reference.' · Shipment clarification', 'body' => $item->body, 'manifest' => [], 'company' => $company];

        return ['key' => 'clarification:'.$id, 'inquiry_id' => $item->inquiry_id, 'rfq_approval_id' => null, 'clarification_id' => $id, 'content' => $content, 'digest' => Processing::hash([$item->id, $item->body, $item->recipient_email, $item->shipment_hash, $item->approved_by, $item->approved_at?->toIso8601String()])];
    }

    public function preview(array $source, MailboxConnection $c): array
    {
        OutboundControl::assertAvailable();
        if (! $c->usable()) {
            $this->fail('Connect or resume the authorized mailbox before authorizing release. Manual workflows remain available.');
        }
        if (Inquiry::findOrFail($source['inquiry_id'])->is_demo !== $c->is_demo) {
            $this->fail('Keep synthetic fixture inquiries on fixture transport and real inquiries on the connected company mailbox. Review a case with matching data provenance.');
        }
        if ($c->provider === 'gmail' && ! $c->verifiedGmailFrom()) {
            $this->fail('Check the Gmail aliases before approval: the selected From must be a verified send-as identity.');
        }
        $s = $source['content'];
        $envelope = $c->envelope($s['company']['reply_email'], $s['company']['reply_name']);
        if (isset($source['expected_envelope']) && ! hash_equals(Processing::hash($source['expected_envelope']), Processing::hash($envelope))) {
            $this->fail('The actual sender or Reply-To changed. Create and approve a new quotation revision.');
        }
        if (! $c->transport_limit || ! $c->rights_confirmed) {
            $this->fail('Admin must record verified mailbox send rights and a tenant transport size limit.');
        }
        $size = strlen($s['body']) + strlen($s['subject']) + 8192;
        foreach ($s['manifest'] as $file) {
            if ($c->provider === 'outlook' && $file['size'] >= 3000000 && $c->mailbox_type === 'shared') {
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
        OutboundControl::assertAvailable(lock: $lock);
        $s = $this->source($envelope->operational_message_approval_id ? 'operations' : ($envelope->followup_stage_id ? 'followup' : ($envelope->client_quotation_approval_id ? 'client_quote' : ($envelope->rfq_approval_id ? 'rfq' : 'clarification'))), $envelope->operational_message_approval_id ?? $envelope->followup_stage_id ?? $envelope->client_quotation_approval_id ?? $envelope->rfq_approval_id ?? $envelope->clarification_id, $staff, $lock);
        $c = $envelope->mailbox;
        if ($lock) {
            $c = MailboxConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
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

    public function connection(array $source): MailboxConnection
    {
        $expected = $source['expected_envelope'] ?? null;
        if (! $expected) {
            return MailboxConnection::current();
        }
        if (isset($expected['connection_id'])) {
            return MailboxConnection::findOrFail($expected['connection_id']);
        }

        return MailboxConnection::where('provider', 'outlook')->where('tenant_id', $expected['tenant_id'] ?? null)->where('target_id', $expected['target_id'] ?? null)->where('account_id', $expected['account_id'] ?? null)->firstOrFail();
    }
}
