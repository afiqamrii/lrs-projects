<?php

namespace App\Actions;

use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\Rfq;
use App\Models\SourcingRound;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\RfqContent;
use App\Support\RfqEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PrepareRfqs
{
    public function handle(Inquiry $inquiry, User $staff, array $ids, int $expected): SourcingRound
    {
        Gate::forUser($staff)->authorize('update', $inquiry);

        return DB::transaction(function () use ($inquiry, $staff, $ids, $expected): SourcingRound {
            CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $record = InquiryWorkflow::locked($inquiry, $expected);
            if ($reasons = RfqEligibility::readiness($record)) {
                throw ValidationException::withMessages(['vendor_ids' => implode(' ', $reasons)]);
            }
            $version = $record->versions()->where('number', $record->shipment_revision)->firstOrFail();
            $round = SourcingRound::firstOrCreate(['shipment_version_id' => $version->id], ['inquiry_id' => $record->id, 'created_by' => $staff->id]);
            $vendors = Vendor::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($vendors->count() !== count($ids) || $vendors->contains(fn (Vendor $v): bool => ! $v->is_active)) {
                throw ValidationException::withMessages(['vendor_ids' => 'Select active vendors from the company directory.']);
            }
            foreach ($vendors as $vendor) {
                $rfq = Rfq::firstOrCreate(['sourcing_round_id' => $round->id, 'vendor_id' => $vendor->id], ['inquiry_id' => $record->id, 'reference' => 'RFQ-'.$record->reference.'-S'.$version->number.'-V'.$vendor->id]);
                if (! $rfq->wasRecentlyCreated) {
                    continue;
                }
                $p = RfqContent::defaults($rfq);
                $contact = $vendor->contacts()->where('is_active', true)->where('is_primary', true)->first();
                if ($contact) {
                    $p['to'] = ['id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email];
                }
                $rfq->revisions()->create(['number' => 1, 'payload' => $p, 'created_by' => $staff->id]);
                Audit::record('Vendor selected; separate RFQ drafted', $rfq, actor: $staff, vendorId: $vendor->id, details: ['shipment_version' => ['before' => null, 'after' => $version->number]]);
            }

            return $round;
        });
    }
}
