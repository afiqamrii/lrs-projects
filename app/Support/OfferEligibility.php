<?php

namespace App\Support;

use App\Models\Inquiry;
use App\Models\OfferComparison;
use App\Models\OfferSelection;
use App\Models\VendorOffer;
use App\Models\VendorOfferRevision;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class OfferEligibility
{
    public static function currentComparison(Inquiry $inquiry): ?OfferComparison
    {
        return OfferComparison::where('inquiry_id', $inquiry->id)->latest('id')->first();
    }

    public static function reasons(VendorOfferRevision $revision, ?OfferComparison $comparison = null): array
    {
        $revision = $revision->fresh();
        $offer = $revision->offer;
        $inquiry = $offer->inquiry;
        $reasons = [];
        if ($offer->supersededBy()) {
            $reasons[] = 'Vendor issued a revised quotation replacing this source.';
        }
        if ($offer->current_number !== $revision->number) {
            $reasons[] = 'Superseded offer version.';
        }
        if ($revision->status !== 'reviewed_complete' || $revision->complete_total === null) {
            $reasons[] = 'Commercial review is incomplete.';
        }
        if (! $inquiry->eligible() || $offer->version->number !== $inquiry->shipment_revision || ! hash_equals($offer->version->snapshot_hash, $inquiry->snapshotHash())) {
            $reasons[] = 'Confirmed shipment has changed or is not ready.';
        }
        $request = $offer->request;
        if ($request->number !== $request->rfq->current_number || $request->status !== 'approved') {
            $reasons[] = 'Reply belongs to an old or unapproved RFQ revision.';
        }
        if (! $offer->vendor->is_active) {
            $reasons[] = 'Vendor is inactive.';
        }
        $contactId = $request->payload['to']['id'] ?? null;
        if (! $contactId || ! $offer->vendor->contacts()->whereKey($contactId)->where('is_active', true)->exists()) {
            $reasons[] = 'RFQ contact is inactive or unavailable.';
        }
        if (! OfferProposal::sourcesCurrent($revision)) {
            $reasons[] = 'Original vendor document evidence is unavailable or changed.';
        }
        if (empty($offer->source['association_confirmed'])) {
            $reasons[] = 'Vendor/source association is unresolved.';
        }
        if (empty($revision->payload['valid_until'])) {
            $reasons[] = 'Validity remains unresolved.';
        } elseif (CarbonImmutable::parse($revision->payload['valid_until'])->isPast()) {
            $reasons[] = 'Offer has expired.';
        }
        if ($comparison) {
            if ($comparison->shipment_version_id !== $offer->shipment_version_id || self::currentComparison($inquiry)?->id !== $comparison->id) {
                $reasons[] = 'Comparison baseline or FX version is stale.';
            }
            if ($revision->complete_total !== null && OfferCosts::fx($revision->complete_total, $revision->currency, $comparison->currency, $comparison->fx) === null) {
                $reasons[] = 'Reviewed comparison FX is missing.';
            }
        }

        return array_values(array_unique($reasons));
    }

    public static function matrix(Inquiry $inquiry, ?OfferComparison $comparison): array
    {
        $rows = [];
        $lowest = null;
        foreach (VendorOffer::where('inquiry_id', $inquiry->id)->with(['vendor', 'request.rfq', 'version'])->orderBy('id')->get() as $offer) {
            $r = $offer->current();
            if (! $r) {
                continue;
            }$reasons = self::reasons($r, $comparison);
            if (! $comparison) {
                $reasons[] = 'Choose a comparison currency first.';
            }
            $total = $comparison && $r->complete_total !== null ? OfferCosts::fx($r->complete_total, $r->currency, $comparison->currency, $comparison->fx) : null;
            if (! $reasons && $total !== null && ($lowest === null || BigDecimal::of($total)->isLessThan($lowest))) {
                $lowest = $total;
            }
            $rows[] = ['offer' => $offer, 'revision' => $r, 'reasons' => $reasons, 'total' => $total];
        }
        foreach ($rows as &$row) {
            $row['lowest'] = $row['reasons'] === [] && $row['total'] !== null && BigDecimal::of($row['total'])->isEqualTo($lowest);
        }

        return $rows;
    }

    public static function selectionReasons(OfferSelection $selection): array
    {
        $selection = $selection->fresh();
        $reasons = self::reasons($selection->revision, $selection->comparison);
        if ($selection->kind !== 'final') {
            $reasons[] = 'Provisional preference is not a Phase 7 pricing basis.';
        }
        if ($selection->superseded_at) {
            $reasons[] = 'Selection has been superseded.';
        }
        if (! hash_equals($selection->digest, Processing::hash($selection->snapshot))) {
            $reasons[] = 'Cost-basis integrity check failed.';
        }

        return array_values(array_unique($reasons));
    }

    public static function pricingBasis(OfferSelection $selection): array
    {
        if ($reasons = self::selectionReasons($selection)) {
            Processing::fail(implode(' ', $reasons));
        }

        return $selection->snapshot;
    }
}
