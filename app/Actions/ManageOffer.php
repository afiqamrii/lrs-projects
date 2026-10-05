<?php

namespace App\Actions;

use App\Http\Requests\OfferRequest;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\MailMessage;
use App\Models\OfferComparison;
use App\Models\OfferSelection;
use App\Models\RfqRevision;
use App\Models\User;
use App\Models\VendorOffer;
use App\Models\VendorOfferRevision;
use App\Support\Audit;
use App\Support\OfferCosts;
use App\Support\OfferEligibility;
use App\Support\Processing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ManageOffer
{
    public function capture(Inquiry $inquiry, User $staff, array $data): VendorOffer
    {
        Gate::forUser($staff)->authorize('viewAny', VendorOffer::class);
        $data = Validator::make($data, ['rfq_revision_id' => 'required|integer', 'alternative' => 'required|string|max:200', 'supersedes_offer_id' => 'nullable|integer', 'source_kind' => 'required|in:manual,email,document', 'manual_text' => 'nullable|string|max:20000', 'message_id' => 'nullable|integer', 'document_ids' => 'nullable|array|max:10', 'document_ids.*' => 'integer|distinct', 'association_confirmed' => 'required|accepted', 'association_reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($inquiry, $staff, $data): VendorOffer {
            $inquiry = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $request = RfqRevision::with(['rfq.round.version', 'rfq.vendor'])->findOrFail($data['rfq_revision_id']);
            if ($request->rfq->inquiry_id !== $inquiry->id || ! $request->approval) {
                Processing::fail('Capture requires an approved exact RFQ revision of this inquiry.');
            }
            $source = ['kind' => $data['source_kind'], 'association_confirmed' => true, 'association_reason' => $data['association_reason'], 'documents' => []];
            if ($data['source_kind'] === 'email') {
                $message = MailMessage::whereKey($data['message_id'] ?? 0)->firstOrFail();
                if ($message->inquiry_id !== $inquiry->id || $message->rfq_revision_id !== $request->id) {
                    Processing::fail('The matched email belongs to a different case or exact RFQ revision. Review matching first.');
                }
                $source += ['message_id' => $message->id, 'message_hash' => $message->source_hash, 'text' => $message->text(), 'classification' => $message->classification, 'sender' => $message->source['from'] ?? null, 'old_reply' => $message->oldRevision()];
            } elseif ($data['source_kind'] === 'manual') {
                if (empty(trim($data['manual_text'] ?? ''))) {
                    Processing::fail('Retain the original commercial note for manual capture.');
                }
                $source['text'] = $data['manual_text'];
            }
            foreach ($data['document_ids'] ?? [] as $id) {
                $doc = InquiryDocument::whereKey($id)->where('inquiry_id', $inquiry->id)->firstOrFail();
                if ($doc->is_archived) {
                    Processing::fail('Only active private source documents can be captured; inspect unscanned originals carefully.');
                }
                if (! in_array($doc->classification, ['freight_quote', 'correspondence', 'other'], true)) {
                    Processing::fail('Customer invoices, packing lists and client RFQs cannot become vendor commercial evidence.');
                }
                $source['documents'][] = ['id' => $doc->id, 'name' => $doc->original_name, 'checksum' => $doc->checksum, 'version' => $doc->version, 'classification' => $doc->classification];
            }
            if ($data['source_kind'] === 'document' && ! $source['documents']) {
                Processing::fail('Select a private vendor quotation document.');
            }
            $identity = Processing::hash(['rfq_revision_id' => $request->id, 'source' => array_diff_key($source, array_flip(['association_reason', 'association_confirmed']))]);
            $existing = VendorOffer::where('inquiry_id', $inquiry->id)->where('source_identity', $identity)->where('alternative', $data['alternative'])->first();
            if ($existing) {
                return $existing;
            }
            $prior = null;
            if (! empty($data['supersedes_offer_id'])) {
                $prior = VendorOffer::whereKey($data['supersedes_offer_id'])->lockForUpdate()->firstOrFail();
                if ($prior->inquiry_id !== $inquiry->id || $prior->vendor_id !== $request->rfq->vendor_id || $prior->supersededBy()) {
                    Processing::fail('A replacement must refer to a current offer from the same vendor and inquiry.');
                } $source['supersedes_offer_id'] = $prior->id;
            }
            $offer = VendorOffer::create(['inquiry_id' => $inquiry->id, 'vendor_id' => $request->rfq->vendor_id, 'rfq_revision_id' => $request->id, 'shipment_version_id' => $request->rfq->round->shipment_version_id, 'sourcing_round_id' => $request->rfq->sourcing_round_id, 'alternative' => $data['alternative'], 'source_identity' => $identity, 'source' => $source, 'created_by' => $staff->id]);
            if ($prior?->current()) {
                $prior->current()->update(['status' => 'superseded']);
                Audit::record('Vendor quotation replaced by revised source', $prior, actor: $staff, vendorId: $prior->vendor_id, details: ['replacement' => ['before' => $prior->id, 'after' => $offer->id]]);
            }
            Audit::record('Vendor offer source captured', $offer, actor: $staff, vendorId: $offer->vendor_id, details: ['commercial_source' => ['before' => null, 'after' => ['rfq_revision_id' => $request->id, 'source_hash' => $identity, 'association' => $data['association_reason']]]]);

            return $offer;
        });
    }

    public static function blank(VendorOffer $offer): array
    {
        $s = $offer->version->snapshot['shipment'];

        return array_intersect_key($s, array_flip(['mode', 'scope', 'origin_country', 'origin_location', 'destination_country', 'destination_location'])) + ['reference' => '', 'received_on' => now(CompanySetting::current()->timezone)->toDateString(), 'issued_on' => null, 'currency' => CompanySetting::current()->currency, 'quantity_statement' => '', 'volume_basis' => 'calculated', 'validity_statement' => 'unknown', 'timing_assessment' => 'pending', 'lines' => [self::blankLine()], 'fx' => [], 'review' => []];
    }

    public static function blankLine(int $number = 1): array
    {
        return ['key' => 'line-'.$number, 'description' => '', 'category' => 'main', 'service' => 'freight', 'state' => 'unpriced', 'basis' => 'flat', 'currency' => CompanySetting::current()->currency, 'tax_treatment' => 'unknown', 'optional' => false, 'confirmed' => false];
    }

    public function locked(VendorOffer $offer, User $staff, int $expected): VendorOffer
    {
        Gate::forUser($staff)->authorize('update', $offer);
        Inquiry::whereKey($offer->inquiry_id)->lockForUpdate()->firstOrFail();
        $record = VendorOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
        if ($record->supersededBy()) {
            Processing::fail('This quotation was replaced by a revised vendor source. Its history is read-only.');
        }
        if ($record->current_number !== $expected) {
            Processing::fail('A newer offer revision exists. Reload and review it; your stale form did not replace it.');
        }

        return $record;
    }

    public function save(VendorOffer $offer, User $staff, array $input, bool $review = false, array $provenance = []): VendorOfferRevision
    {
        $input['fx'] = OfferRequest::filledFx($input['fx'] ?? []);
        $data = Validator::make($input, OfferRequest::commercialRules(), [], OfferRequest::commercialAttributes())->validate();

        return DB::transaction(function () use ($offer, $staff, $data, $review, $provenance): VendorOfferRevision {
            $record = $this->locked($offer, $staff, (int) $data['expected_revision']);
            $p = array_diff_key($data, array_flip(['expected_revision', 'change_reason']));
            if (! empty($p['valid_until'])) {
                $p['valid_until'] = CarbonImmutable::parse($p['valid_until'], CompanySetting::current()->timezone)->utc()->toIso8601String();
            }
            $p['proposal_review'] = $provenance;
            $p['review'] = $review ? ($p['review'] ?? []) : [];
            foreach ($p['lines'] as &$line) {
                $line['confirmed'] = $review && ! empty($line['confirmed']);
                $line['optional'] = ! empty($line['optional']);
            }
            unset($line);
            $c = OfferCosts::calculate($p, $record->version->snapshot['shipment']);
            $number = $record->current_number + 1;
            $r = VendorOfferRevision::create(['vendor_offer_id' => $record->id, 'number' => $number, 'status' => $review ? ($c['gaps'] ? 'reviewed_gaps' : 'reviewed_complete') : 'needs_review', 'payload' => $p, 'calculation' => $c, 'gaps' => $c['gaps'], 'currency' => $p['currency'], 'quoted_total' => $p['quoted_total'] ?? null, 'known_total' => $c['known_total'], 'complete_total' => $review ? $c['complete_total'] : null, 'digest' => Processing::hash(['payload' => $p, 'calculation' => $c]), 'change_reason' => $data['change_reason'], 'created_by' => $staff->id, 'reviewed_by' => $review ? $staff->id : null, 'reviewed_at' => $review ? now() : null]);
            foreach ($c['lines'] as $line) {
                $r->charges()->create(['line_key' => $line['key'], 'evidence' => $line, 'state' => $line['state'], 'basis' => $line['basis'], 'currency' => $line['currency'], 'rate' => $line['rate'] ?? null, 'quantity' => $line['quantity'], 'minimum_charge' => $line['minimum_charge'] ?? null, 'minimum_quantity' => $line['minimum_quantity'] ?? null, 'amount' => $line['amount']]);
            }
            if ($previous = $record->current()) {
                $previous->update(['status' => 'superseded']);
            }
            $record->update(['current_number' => $number]);
            Audit::record($review ? 'Vendor offer reviewed' : 'Vendor offer corrected', $record, actor: $staff, vendorId: $record->vendor_id, details: ['commercial_revision' => ['before' => $number - 1, 'after' => ['number' => $number, 'digest' => $r->digest, 'gaps' => $c['gaps'], 'state' => $r->status]]]);

            return $r;
        });
    }

    public function comparison(Inquiry $inquiry, User $staff, array $data): OfferComparison
    {
        Gate::forUser($staff)->authorize('viewAny', VendorOffer::class);
        $data['fx'] = OfferRequest::filledFx($data['fx'] ?? []);
        $data = Validator::make($data, ['expected_comparison' => 'required|integer|min:0', 'currency' => OfferRequest::commercialRules()['currency'], 'reason' => 'required|string|max:2000', 'fx' => 'nullable|array|max:12'] + array_filter(OfferRequest::commercialRules(), fn (string $key): bool => str_starts_with($key, 'fx.'), ARRAY_FILTER_USE_KEY), [], OfferRequest::commercialAttributes())->validate();

        return DB::transaction(function () use ($inquiry, $staff, $data): OfferComparison {
            $case = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if (! $case->eligible()) {
                Processing::fail('Confirm the shipment before comparing costs.');
            }
            $previous = OfferEligibility::currentComparison($case);
            if (($previous?->id ?? 0) !== (int) $data['expected_comparison']) {
                Processing::fail('Comparison changed. Reload the current currency and FX version.');
            }
            $version = $case->versions()->where('number', $case->shipment_revision)->firstOrFail();
            $p = ['shipment_version_id' => $version->id, 'currency' => $data['currency'], 'fx' => $data['fx'] ?? []];
            foreach ($p['fx'] as $from => $f) {
                if ($from !== $f['from'] || $f['to'] !== $p['currency'] || OfferCosts::fx('1', $from, $p['currency'], [$from => $f]) === null) {
                    Processing::fail('FX keys, direction and target must match the comparison currency.');
                }
            }
            if ($previous && hash_equals($previous->digest, Processing::hash($p))) {
                return $previous;
            }
            $r = OfferComparison::create($p + ['inquiry_id' => $case->id, 'digest' => Processing::hash($p), 'reason' => $data['reason'], 'created_by' => $staff->id, 'created_at' => now()]);
            Audit::record('Vendor comparison basis saved', $case, actor: $staff, details: ['comparison' => ['before' => $previous?->id, 'after' => $r->id]]);

            return $r;
        });
    }

    public function select(Inquiry $inquiry, User $staff, array $data): OfferSelection
    {
        Gate::forUser($staff)->authorize('viewAny', VendorOffer::class);
        $data = Validator::make($data, ['offer_revision_id' => 'required|integer', 'comparison_id' => 'required|integer', 'expected_selection' => 'required|integer|min:0', 'kind' => 'required|in:final,provisional', 'reason' => 'required|string|min:8|max:2000'])->validate();

        return DB::transaction(function () use ($inquiry, $staff, $data): OfferSelection {
            $case = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $current = OfferSelection::where('inquiry_id', $case->id)->whereNull('superseded_at')->lockForUpdate()->first();
            if (($current?->id ?? 0) !== (int) $data['expected_selection']) {
                Processing::fail('A newer selection exists. Reload its exact saved cost basis.');
            }
            $r = VendorOfferRevision::findOrFail($data['offer_revision_id']);
            if ($r->offer->inquiry_id !== $case->id) {
                abort(404);
            }
            $comparison = OfferComparison::where('inquiry_id', $case->id)->findOrFail($data['comparison_id']);
            if (OfferEligibility::currentComparison($case)?->id !== $comparison->id) {
                Processing::fail('The comparison/FX version changed.');
            }
            $reasons = OfferEligibility::reasons($r, $comparison);
            if ($data['kind'] === 'final' && $reasons) {
                Processing::fail(implode(' ', $reasons));
            }
            $offer = $r->offer;
            DB::table('vendors')->where('id', $offer->vendor_id)->lockForUpdate()->first();
            DB::table('contacts')->where('id', $offer->request->payload['to']['id'] ?? 0)->lockForUpdate()->first();
            $r->unsetRelation('offer');
            $reasons = OfferEligibility::reasons($r, $comparison);
            if ($data['kind'] === 'final' && $reasons) {
                Processing::fail(implode(' ', $reasons));
            }
            $snapshot = ['schema' => 'lrs-cost-basis-1', 'kind' => $data['kind'], 'inquiry_id' => $case->id, 'shipment_version_id' => $offer->shipment_version_id, 'shipment' => $offer->version->snapshot, 'shipment_hash' => $offer->version->snapshot_hash, 'sourcing_round_id' => $offer->sourcing_round_id, 'rfq_revision_id' => $offer->rfq_revision_id, 'rfq_snapshot' => $offer->request->payload, 'vendor' => ['id' => $offer->vendor_id, 'name' => $offer->vendor->company_name], 'alternative' => $offer->alternative, 'offer_revision_id' => $r->id, 'offer_revision' => $r->number, 'offer_digest' => $r->digest, 'commercial' => $r->payload, 'calculation' => $r->calculation, 'source' => $offer->source, 'comparison' => ['id' => $comparison->id, 'currency' => $comparison->currency, 'fx' => $comparison->fx, 'digest' => $comparison->digest, 'total' => $r->complete_total !== null ? OfferCosts::fx($r->complete_total, $r->currency, $comparison->currency, $comparison->fx) : null], 'unresolved' => $reasons, 'reviewer' => ['id' => $r->reviewed_by, 'name' => $r->reviewer?->name, 'at' => $r->reviewed_at?->toIso8601String()], 'selected_by' => ['id' => $staff->id, 'name' => $staff->name], 'selected_at' => now()->toIso8601String(), 'reason' => $data['reason']];
            if ($current) {
                $current->update(['superseded_at' => now()]);
            }
            $selection = OfferSelection::create(['inquiry_id' => $case->id, 'vendor_offer_revision_id' => $r->id, 'offer_comparison_id' => $comparison->id, 'kind' => $data['kind'], 'snapshot' => $snapshot, 'digest' => Processing::hash($snapshot), 'reason' => $data['reason'], 'selected_by' => $staff->id, 'selected_at' => now()]);
            Audit::record('Vendor '.$data['kind'].' selection recorded', $case, actor: $staff, vendorId: $offer->vendor_id, details: ['selection' => ['before' => $current?->id, 'after' => ['id' => $selection->id, 'digest' => $selection->digest]]]);

            return $selection;
        });
    }
}
