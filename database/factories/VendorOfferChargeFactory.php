<?php

namespace Database\Factories;

use App\Models\VendorOfferRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorOfferChargeFactory extends Factory
{
    public function definition(): array
    {
        return ['vendor_offer_revision_id' => VendorOfferRevision::factory(), 'line_key' => 'line-1', 'state' => 'unpriced', 'basis' => 'flat', 'currency' => 'MYR', 'evidence' => ['description' => 'Unreviewed main freight', 'source_ref' => 'Fictional quotation line 1']];
    }
}
