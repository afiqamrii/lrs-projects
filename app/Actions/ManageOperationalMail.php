<?php

namespace App\Actions;

use App\Http\Requests\OperationalMessageRequest;
use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\HandoffRevision;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\OperationalMessage;
use App\Models\OperationalMessageApproval;
use App\Models\User;
use App\Models\VendorReconfirmation;
use App\Support\Audit;
use App\Support\LifecycleEligibility;
use App\Support\MailRelease;
use App\Support\OfferCosts;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ManageOperationalMail
{
    public function messages(object $parent): Builder
    {
        return OperationalMessage::where($parent instanceof VendorReconfirmation ? 'vendor_reconfirmation_id' : 'handoff_revision_id', $parent->id)->orderByDesc('number');
    }

    public function basis(object $parent): array
    {
        if ($parent instanceof VendorReconfirmation) {
            if ($reasons = LifecycleEligibility::requestReasons($parent)) {
                Processing::fail(implode(' ', $reasons));
            }

            return ['kind' => 'reconfirmation', 'request' => $parent->id, 'decision' => $parent->decision->digest, 'selection' => $parent->selection->snapshot, 'generation' => $parent->inquiry->fresh()->lifecycle_generation, 'confirmation' => $parent->current()?->digest];
        }
        if (! $parent->approval) {
            Processing::fail('Approve the exact current handoff before preparing a booking instruction.');
        }
        LifecycleEligibility::approved($parent->approval);
        if (! HandoffEvent::where('handoff_approval_id', $parent->approval->id)->where('kind', 'handed_to_operations')->exists()) {
            Processing::fail('Record the actual handoff to operations before a booking instruction.');
        }
        if (HandoffEvent::where('handoff_approval_id', $parent->approval->id)->where('kind', 'booking_confirmed')->exists()) {
            Processing::fail('Booking is already confirmed. Review the existing evidence; no new booking instruction.');
        }

        return ['kind' => 'booking', 'handoff' => $parent->id, 'approval' => $parent->approval->digest, 'dependencies' => LifecycleEligibility::readiness($parent->handoff->inquiry, $parent->snapshot['evidence'])['dependency_digest']];
    }

    public function defaults(object $parent): array
    {
        $request = $parent instanceof VendorReconfirmation ? $parent : $parent->confirmation->request;
        $selection = $request->selection;
        $i = $request->inquiry;
        $s = $selection->snapshot['shipment']['shipment'];
        $commercial = $selection->snapshot['commercial'];
        $vendor = $selection->revision->offer->vendor;
        $contact = $vendor->contacts()->where('is_active', true)->orderByDesc('is_primary')->first();
        $kind = $parent instanceof VendorReconfirmation ? 'Reconfirmation' : 'Booking instruction';
        $groups = array_map(fn (array $group): string => ($group['quantity'] ?? '?').' × '.($s['mode'] === 'FCL' ? ($group['container_type'] ?? 'container') : ($group['packaging_type'] ?? 'packages')), $s[$s['mode'] === 'FCL' ? 'containers' : 'packages'] ?? []);
        $totals = Shipment::totals($s);
        $quantity = implode('; ', $groups).' · '.($totals['weight'] ?? 'Unconfirmed').' kg · '.($totals['volume'] ?? 'Unconfirmed').' CBM';
        $ref = $i->reference.' · '.$kind.' '.$parent->id;
        $lines = ['Dear '.($contact?->name ?? 'Vendor').',', '', $kind.' · '.$i->reference,
            'Client quotation: '.$request->decision->snapshot['quotation_reference'].' · revision '.$request->decision->revision->number,
            'Shipment: '.$s['mode'].' · '.$s['origin_location'].' to '.$s['destination_location'],
            'Scope: '.ucfirst(str_replace('_', ' ', $s['scope'] ?? '')).(! empty($s['services']) ? ' · services '.implode(', ', $s['services']) : ''),
            'Cargo: '.$s['cargo_description'], 'Equipment / packages: '.$quantity,
            'Cargo ready date: '.$s['cargo_ready_date'], 'Requested arrival: '.($s['arrival_date'] ?? 'Not stated'),
            'Selected vendor rate: '.$selection->revision->currency.' '.OfferCosts::money($selection->revision->complete_total, $selection->revision->currency),
            'Selected rate validity: '.($commercial['valid_until'] ?? 'Not stated')];
        foreach ($selection->snapshot['calculation']['lines'] ?? [] as $line) {
            if (! $line['optional']) {
                $lines[] = 'Charge: '.($line['description'] ?? $line['service']).' · '.($line['currency'] ?? $selection->revision->currency).' '.($line['amount'] ?? 'Unpriced').' · '.($line['basis'] ?? '');
            }
        }
        $lines[] = 'Selected conditions: '.($commercial['conditions'] ?? 'See selected offer terms.');
        if ($parent instanceof VendorReconfirmation) {
            $lines[] = 'Please expressly confirm the quoted rate and charges, service scope, available dates and equipment/capacity, and list any outstanding conditions. This is a reconfirmation request; no booking instruction is authorized by this message.';
        } else {
            $e = $parent->snapshot['evidence'];
            $lines[] = 'Operations owner: '.($parent->snapshot['dependencies']['owner']['name'] ?? '');
            foreach (['pickup', 'delivery'] as $type) {
                if (! empty($e[$type.'_contact'])) {
                    $lines[] = ucfirst($type).' contact: '.$e[$type.'_contact'].' · address: '.($s[$type.'_address'] ?? '');
                }
            }
            $lines[] = 'Cargo availability evidence: '.($e['cargo_evidence'] ?? '');
            $lines[] = 'Please arrange the shipment under the explicitly agreed rate, scope and dates above. Reply with the actual vendor booking reference and confirmed dates. Booking remains unconfirmed in LRS until staff reviews actual booking evidence.';
        }
        $lines[] = '';
        $lines[] = 'Kind regards,';
        $lines[] = RfqContent::company()['reply_name'];

        return ['to_contact_id' => $contact?->id, 'cc_contact_ids' => [], 'subject' => $ref, 'body' => implode("\n", $lines), 'document_ids' => [], 'reason' => '', 'expected_revision' => $this->messages($parent)->first()?->number ?? 0];
    }

    public function save(object $parent, User $staff, array $input): OperationalMessage
    {
        $d = Validator::make($input, OperationalMessageRequest::inputRules())->validate();

        return DB::transaction(function () use ($parent, $staff, $d) {
            $i = $parent instanceof VendorReconfirmation ? $parent->inquiry : $parent->handoff->inquiry;
            app(ManageLifecycle::class)->lock($i, $staff);
            $basis = $this->basis($parent->fresh());
            $latest = $this->messages($parent)->first();
            if ((int) $d['expected_revision'] !== ($latest?->number ?? 0)) {
                Processing::fail('The vendor message changed. Reload before saving a new version.');
            }
            $request = $parent instanceof VendorReconfirmation ? $parent : $parent->confirmation->request;
            $vendor = $request->selection->revision->offer->vendor;
            $ids = array_unique(array_merge([(int) $d['to_contact_id']], array_map('intval', $d['cc_contact_ids'] ?? [])));
            $recipients = [];
            foreach ($ids as $id) {
                $contact = $vendor->contacts()->whereKey($id)->where('is_active', true)->first();
                if (! $contact || ! filter_var($contact->email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $contact->name)) {
                    Processing::fail('Select active email contacts belonging to the accepted vendor.');
                }
                $recipients[] = $contact->only(['id', 'name', 'email']);
            }
            $manifest = [];
            $docs = LifecycleEligibility::documents($i, $d['document_ids'] ?? []);
            if ($docs && empty($d['disclosure_confirmed'])) {
                Processing::fail('Review and explicitly confirm the complete content of each selected attachment for vendor disclosure.');
            }
            foreach ($docs as $doc) {
                if ($doc['classification'] === 'freight_quote' || HandoffRevision::where('pdf_checksum', $doc['checksum'])->exists() || HandoffApproval::where('pdf_checksum', $doc['checksum'])->exists()) {
                    Processing::fail('Client commercial quotes and internal handoff exports cannot be attached to vendor messages. Prepare and review an appropriate shipment-only file.');
                }
                $file = $i->documents()->findOrFail($doc['id']);
                $manifest[] = ['document_id' => $file->id, 'version' => (int) $file->version, 'checksum' => $file->checksum, 'name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size, 'classification' => $file->classification, 'scan_status' => $file->scan_status, 'prepared_from_id' => $file->prepared_from_id];
            }
            $prior = $this->prior($parent);
            $sent = $prior->first(fn ($x) => in_array($x->status, ['accepted', 'observed'], true));
            if ($prior->contains(fn ($x) => in_array($x->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true))) {
                Processing::fail('A prior operational message is pending or uncertain. Cancel or reconcile before another message.');
            }
            if ($sent && (empty($d['resend_confirmed']) || ! $prior->contains(fn ($x) => $x->id === (int) ($d['resend_of_id'] ?? 0) && in_array($x->status, ['accepted', 'observed', 'failed'], true)))) {
                Processing::fail('A prior message reached provider acceptance. Link a resolved dispatch and explicitly confirm the deliberate repeat.');
            }
            $content = ['reference' => $i->reference.'-'.$basis['kind'].'-'.$parent->id, 'revision' => ($latest?->number ?? 0) + 1, 'inquiry_id' => $i->id, 'vendor_name' => $vendor->company_name,
                'to' => array_shift($recipients), 'cc' => $recipients, 'subject' => $d['subject'], 'body' => $d['body'], 'manifest' => $manifest, 'company' => RfqContent::company(), 'disclosure_confirmed' => ! empty($d['disclosure_confirmed'])];
            $m = OperationalMessage::create(['inquiry_id' => $i->id, 'vendor_reconfirmation_id' => $parent instanceof VendorReconfirmation ? $parent->id : null, 'handoff_revision_id' => $parent instanceof HandoffRevision ? $parent->id : null,
                'number' => $content['revision'], 'kind' => $basis['kind'], 'content' => $content, 'dependency_digest' => Processing::hash($basis), 'digest' => Processing::hash($content), 'resend_of_id' => $d['resend_of_id'] ?? null, 'reason' => $d['reason'], 'created_by' => $staff->id, 'created_at' => now()]);
            Audit::record('Operational vendor message drafted', $m, actor: $staff);

            return $m;
        }, 3);
    }

    private function prior(object $parent): Collection
    {
        return MailDispatch::whereHas('envelope.operationalMessageApproval.message', fn ($m) => $m->where($parent instanceof VendorReconfirmation ? 'vendor_reconfirmation_id' : 'handoff_revision_id', $parent->id))->latest('id')->get();
    }

    public function assertMessage(OperationalMessage $m): void
    {
        $parent = $m->kind === 'reconfirmation' ? $m->reconfirmation : $m->handoff;
        $basis = $this->basis($parent);
        if ($this->messages($parent)->first()?->id !== $m->id || Processing::hash($basis) !== $m->dependency_digest || Processing::hash($m->content) !== $m->digest) {
            Processing::fail('Operational content or its exact accepted basis changed. Prepare and approve a new message revision.');
        }
        $vendor = ($m->kind === 'reconfirmation' ? $parent : $parent->confirmation->request)->selection->revision->offer->vendor;
        foreach (array_merge([$m->content['to']], $m->content['cc']) as $recipient) {
            if (! $vendor->contacts()->whereKey($recipient['id'])->where('is_active', true)->where('email', $recipient['email'])->where('name', $recipient['name'])->exists()) {
                Processing::fail('Vendor recipient changed or is inactive.');
            }
        }
        $docs = LifecycleEligibility::documents($m->inquiry, array_column($m->content['manifest'], 'document_id'));
        foreach ($m->content['manifest'] as $file) {
            $actual = collect($docs)->firstWhere('id', $file['document_id']);
            if (! $actual || $actual['checksum'] !== $file['checksum'] || (int) $actual['version'] !== $file['version'] || $actual['classification'] !== $file['classification']) {
                Processing::fail('An approved attachment identity or classification changed.');
            }
        }
        $prior = $this->prior($parent)->filter(fn ($x) => $x->envelope->operationalMessageApproval->operational_message_id !== $m->id);
        if ($prior->contains(fn ($x) => in_array($x->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true))) {
            Processing::fail('A different operational message is pending or uncertain.');
        }
        if ($prior->contains(fn ($x) => in_array($x->status, ['accepted', 'observed'], true)) && ! $prior->contains(fn ($x) => $x->id === $m->resend_of_id && in_array($x->status, ['accepted', 'observed', 'failed'], true))) {
            Processing::fail('A deliberate repeated message needs its prior resolved dispatch.');
        }
    }

    public function preview(OperationalMessage $m, User $staff): array
    {
        LifecycleEligibility::staff($staff);
        $this->assertMessage($m);

        return app(MailRelease::class)->preview(['key' => 'operations-draft:'.$m->id, 'inquiry_id' => $m->inquiry_id, 'content' => $m->content, 'digest' => $m->digest], MailboxConnection::current());
    }

    public function approve(OperationalMessage $m, User $staff, string $digest): OperationalMessageApproval
    {
        return DB::transaction(function () use ($m, $staff, $digest) {
            app(ManageLifecycle::class)->lock($m->inquiry, $staff);
            $p = $this->preview($m, $staff);
            if (Processing::hash($p) !== $digest) {
                Processing::fail('The exact content, recipients, files or actual envelope changed. Reload the approval preview.');
            }
            $a = OperationalMessageApproval::firstOrCreate(['operational_message_id' => $m->id], ['snapshot' => ['schema' => 'lrs-operational-approval-1', 'message_digest' => $m->digest, 'content' => $m->content, 'envelope' => $p['envelope'], 'reviewer' => $staff->only(['id', 'name'])],
                'digest' => Processing::hash(['schema' => 'lrs-operational-approval-1', 'message_digest' => $m->digest, 'content' => $m->content, 'envelope' => $p['envelope'], 'reviewer' => $staff->only(['id', 'name'])]), 'approved_by' => $staff->id, 'approved_at' => now()]);
            Audit::record('Exact operational message approved', $a, actor: $staff, details: ['approval' => ['before' => null, 'after' => ['message' => $m->id, 'kind' => $m->kind]]]);

            return $a;
        }, 3);
    }

    public function source(OperationalMessageApproval $a, User $staff, bool $lock = false): array
    {
        LifecycleEligibility::staff($staff);
        if ($lock) {
            app(ManageLifecycle::class)->lock($a->message->inquiry, $staff);
        }
        $this->assertMessage($a->message);
        if (Processing::hash($a->snapshot) !== $a->digest || $a->snapshot['message_digest'] !== $a->message->digest || ! User::whereKey($a->approved_by)->where('is_active', true)->exists()) {
            Processing::fail('Operational approval integrity or reviewer authority changed.');
        }

        return ['key' => 'operations:'.$a->id, 'inquiry_id' => $a->message->inquiry_id, 'operational_message_approval_id' => $a->id, 'rfq_approval_id' => null, 'clarification_id' => null, 'content' => $a->snapshot['content'], 'digest' => $a->digest, 'expected_envelope' => $a->snapshot['envelope']];
    }
}
