<?php

namespace Database\Factories;

use App\Models\OfferComparison;
use App\Models\User;
use App\Models\VendorOfferRevision;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfferSelectionFactory extends Factory
{
    public function definition(): array
    {
        return ['vendor_offer_revision_id' => VendorOfferRevision::factory(), 'inquiry_id' => fn (array $a): int => VendorOfferRevision::findOrFail($a['vendor_offer_revision_id'])->offer->inquiry_id, 'offer_comparison_id' => fn (array $a): int => OfferComparison::factory()->create(['inquiry_id' => $a['inquiry_id'], 'shipment_version_id' => VendorOfferRevision::findOrFail($a['vendor_offer_revision_id'])->offer->shipment_version_id])->id, 'kind' => 'provisional', 'snapshot' => fn (array $a): array => ['schema' => 'lrs-cost-basis-1', 'kind' => 'provisional', 'inquiry_id' => $a['inquiry_id'], 'offer_revision_id' => $a['vendor_offer_revision_id'], 'unresolved' => ['Unreviewed fixture is not a pricing basis']], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'reason' => 'Awaiting reviewed complete costs', 'selected_by' => User::factory(), 'selected_at' => now()];
    }
}
