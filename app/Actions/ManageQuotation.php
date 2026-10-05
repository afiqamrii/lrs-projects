<?php

namespace App\Actions;

use App\Http\Requests\QuotationRequest;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationApproval;
use App\Models\ClientQuotationRevision;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\OfferSelection;
use App\Models\User;
use App\Support\Audit;
use App\Support\MailRelease;
use App\Support\Processing;
use App\Support\QuotationContent;
use App\Support\QuotationEligibility;
use App\Support\QuotationPdf;
use App\Support\QuotationPricing;
use App\Support\RfqContent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ManageQuotation
{
    public function locked(Inquiry $inquiry, User $staff): Inquiry
    {
        Gate::forUser($staff)->authorize('update', $inquiry);
        CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();

        return Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
    }

    public function save(Inquiry $inquiry, User $staff, array $data): ClientQuotationRevision
    {
        $data = Validator::make($data, QuotationRequest::quotationRules())->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($inquiry, $staff, $data, &$path): ClientQuotationRevision {
                $case = $this->locked($inquiry, $staff);
                $quote = ClientQuotation::firstOrCreate(['inquiry_id' => $case->id], ['reference' => 'LRS-Q-'.now()->format('Y').'-'.str_pad((string) $case->id, 6, '0', STR_PAD_LEFT)]);
                $quote = ClientQuotation::whereKey($quote->id)->lockForUpdate()->firstOrFail();
                if ($quote->current_number !== (int) $data['expected_revision']) {
                    Processing::fail('A newer quotation revision exists. Reload before saving; your changes did not overwrite it.');
                }
                $selection = OfferSelection::where('inquiry_id', $case->id)->whereKey($data['offer_selection_id'])->lockForUpdate()->firstOrFail();
                $basis = $selection->snapshot;
                $p = array_diff_key($data, array_flip(['expected_revision', 'change_reason', 'intent', 'resend_of_id', 'resend_confirmed']));
                foreach (['markup_confirmed', 'terms_confirmed'] as $key) {
                    $p[$key] = ! empty($p[$key]);
                }
                foreach (['markup_percent', 'customer_tax_rate', 'vendor_tax_evidence', 'customer_tax_evidence', 'tax_charge_description', 'subject', 'body', 'inclusions', 'exclusions', 'conditions', 'internal_notes'] as $key) {
                    $p[$key] = $p[$key] ?? null;
                }
                $p['company'] = RfqContent::company() + ['address' => CompanySetting::current()->public_contact_address, 'phone' => CompanySetting::current()->public_contact_phone];
                $p['client'] = ['id' => $case->client_id, 'name' => $case->client?->company_name, 'address' => $case->client?->address];
                $p['cc'] = [];
                $recipient = function (int $id) use ($case): array {
                    $contact = $case->client?->contacts()->whereKey($id)->where('is_active', true)->lockForUpdate()->first();
                    if (! $contact) {
                        Processing::fail('Recipients must be active authorized contacts of this inquiry’s client.');
                    }

                    return ['id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email];
                };
                $p['to'] = empty($p['to_contact_id']) ? null : $recipient((int) $p['to_contact_id']);
                foreach ($p['cc_contact_ids'] ?? [] as $id) {
                    $item = $recipient((int) $id);
                    if ($item['id'] !== ($p['to']['id'] ?? null) && ! in_array($item['email'], array_column($p['cc'], 'email'), true) && $item['email'] !== ($p['to']['email'] ?? null)) {
                        $p['cc'][] = $item;
                    }
                }
                $expires = QuotationContent::deadline($p['valid_until'] ?? null, $p['company']['timezone']);
                $p['deadline'] = $expires?->toIso8601String();
                $pricing = QuotationPricing::calculate($basis, $p);
                $number = $quote->current_number + 1;
                $p['customer'] = QuotationContent::customer($p, $pricing, $basis, $quote->reference, $number);
                $state = $data['intent'] === 'review' ? 'needs_review' : 'draft';
                $resend = $data['resend_of_id'] ?? null;
                if ($resend) {
                    $d = MailDispatch::whereKey($resend)->firstOrFail();
                    if (empty($data['resend_confirmed']) || $d->envelope->inquiry_id !== $case->id || ! $d->envelope->client_quotation_approval_id || in_array($d->status, ['queued', 'preparing', 'ready', 'submitting', 'uncertain'], true)) {
                        Processing::fail('A deliberate resend requires a resolved earlier quotation dispatch, explicit confirmation and a reason.');
                    }
                }
                $bytes = QuotationPdf::render($p['customer'], $state === 'draft' || $pricing['gaps'] !== [] || ! $p['terms_confirmed']);
                $path = 'client-quotations/'.$case->id.'/'.Str::uuid().'.pdf';
                if (! Storage::disk('inquiry_documents')->put($path, $bytes)) {
                    Processing::fail('The private PDF could not be saved. Your previous quotation is unchanged.');
                }
                $r = ClientQuotationRevision::make(['client_quotation_id' => $quote->id, 'offer_selection_id' => $selection->id, 'number' => $number, 'state' => $state,
                    'payload' => $p, 'pricing' => $pricing, 'source_snapshot' => $basis, 'pdf_path' => $path, 'pdf_checksum' => hash('sha256', $bytes), 'pdf_size' => strlen($bytes),
                    'change_reason' => $data['change_reason'], 'resend_of_id' => $resend, 'created_by' => $staff->id, 'author_name' => $staff->name,
                    'expires_at' => $expires, 'created_at' => now()->startOfSecond()]);
                $r->digest = QuotationEligibility::digest($r);
                $r->save();
                $quote->update(['current_number' => $number]);
                app(ManageLifecycle::class)->stop($case, 'A new client quotation revision supersedes the prior reminder authorization.', $staff);
                Audit::record('Client quotation revision saved', $r, actor: $staff, details: ['revision' => ['before' => null, 'after' => ['number' => $number, 'state' => $state, 'selection_id' => $selection->id, 'digest' => $r->digest]]]);

                return $r;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('inquiry_documents')->delete($path);
            }
            throw $e;
        }
    }

    public function reviewSnapshot(ClientQuotationRevision $r): array
    {
        QuotationEligibility::assert($r);
        $approval = $r->approval;
        $content = $approval?->snapshot['content'] ?? QuotationContent::mail($r);
        $source = ['key' => 'client_quote_review:'.$r->id, 'inquiry_id' => $r->quotation->inquiry_id, 'content' => $content, 'digest' => $r->digest];
        $release = app(MailRelease::class);
        if ($approval) {
            $source['expected_envelope'] = $approval->snapshot['envelope'];
        }
        $connection = $release->connection($source);
        $preview = $release->preview($source, $connection);
        if ($approval && ! hash_equals(Processing::hash($approval->snapshot['envelope']), Processing::hash($preview['envelope']))) {
            Processing::fail('The actual sender changed. Create and approve a new quotation revision.');
        }

        return ['schema' => 'lrs-client-approval-1', 'revision_digest' => $r->digest, 'source_digest' => $r->selection->digest,
            'pricing' => $r->pricing, 'customer' => $r->payload['customer'], 'content' => $preview['content'], 'envelope' => $preview['envelope'],
            'identity_hash' => $connection->identity_hash];
    }

    public function approve(ClientQuotationRevision $revision, User $staff, string $digest): ClientQuotationApproval
    {
        return DB::transaction(function () use ($revision, $staff, $digest): ClientQuotationApproval {
            $this->lockRevision($revision, $staff);
            $r = $revision->fresh();
            $snapshot = $this->reviewSnapshot($r);
            if (! hash_equals($digest, Processing::hash($snapshot))) {
                Processing::fail('The exact review changed. Reload and inspect the PDF, pricing, email, recipients and sender again.');
            }
            $snapshot['reviewer'] = ['id' => $staff->id, 'name' => $staff->name];
            $snapshot['approved_at'] = now()->startOfSecond()->toIso8601String();
            $approvalDigest = Processing::hash($snapshot);
            $a = ClientQuotationApproval::firstOrCreate(['client_quotation_revision_id' => $r->id], ['snapshot' => $snapshot, 'digest' => $approvalDigest, 'approved_by' => $staff->id, 'reviewer_name' => $staff->name, 'approved_at' => CarbonImmutable::parse($snapshot['approved_at'])]);
            $source = app(MailRelease::class)->source('client_quote', $a->id, $staff);
            $release = app(MailRelease::class);
            $preview = $release->preview($source, $release->connection($source));
            app(MailOutbox::class)->authorize('client_quote', $a->id, $staff, Processing::hash($preview));
            Audit::record('Exact client quotation approved', $a, actor: $staff, details: ['approval' => ['before' => null, 'after' => ['revision' => $r->number, 'digest' => $digest, 'pdf_checksum' => $r->pdf_checksum]]]);

            return $a;
        });
    }

    public function lockRevision(ClientQuotationRevision $r, User $staff): void
    {
        $this->locked($r->quotation->inquiry, $staff);
        ClientQuotation::whereKey($r->client_quotation_id)->lockForUpdate()->firstOrFail();
        $selection = OfferSelection::whereKey($r->offer_selection_id)->lockForUpdate()->firstOrFail();
        $offer = $selection->revision->offer;
        DB::table('vendors')->where('id', $offer->vendor_id)->lockForUpdate()->first();
        DB::table('contacts')->where('id', $offer->request->payload['to']['id'] ?? 0)->lockForUpdate()->first();
        DB::table('clients')->where('id', $r->quotation->inquiry->client_id)->lockForUpdate()->first();
        foreach (array_merge($r->payload['to'] ? [$r->payload['to']] : [], $r->payload['cc']) as $contact) {
            DB::table('client_contacts')->where('id', $contact['id'])->lockForUpdate()->first();
        }
        foreach ($r->source_snapshot['source']['documents'] ?? [] as $file) {
            DB::table('inquiry_documents')->where('id', $file['id'])->lockForUpdate()->first();
        }
    }
}
