<?php

namespace Database\Factories;

use App\Models\ClientDecision;
use App\Models\VendorReconfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorReconfirmation> */
class VendorReconfirmationFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['client_decision_id' => fn (): int => ClientDecision::where('outcome', 'accepted')->latest('id')->firstOrFail()->id, 'inquiry_id' => fn (array $a): int => ClientDecision::findOrFail($a['client_decision_id'])->inquiry_id, 'offer_selection_id' => fn (array $a): int => ClientDecision::findOrFail($a['client_decision_id'])->revision->offer_selection_id, 'shipment_version_id' => fn (array $a): int => ClientDecision::findOrFail($a['client_decision_id'])->revision->selection->snapshot['shipment_version_id'], 'current_number' => 0];
    }
}
