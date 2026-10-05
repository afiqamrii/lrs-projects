<?php

namespace Database\Factories;

use App\Models\ShipmentVersion;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfferComparisonFactory extends Factory
{
    public function definition(): array
    {
        return ['shipment_version_id' => ShipmentVersion::factory(), 'inquiry_id' => fn (array $a): int => ShipmentVersion::findOrFail($a['shipment_version_id'])->inquiry_id, 'currency' => 'MYR', 'fx' => [], 'digest' => fn (array $a): string => Processing::hash(['shipment_version_id' => $a['shipment_version_id'], 'currency' => $a['currency'], 'fx' => $a['fx']]), 'reason' => 'Fictional comparison fixture', 'created_by' => User::factory(), 'created_at' => now()];
    }
}
