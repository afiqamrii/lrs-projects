<?php

namespace Database\Factories;

use App\Models\ShipmentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourcingRoundFactory extends Factory
{
    public function definition(): array
    {
        return ['shipment_version_id' => ShipmentVersion::factory(), 'inquiry_id' => fn (array $a): int => ShipmentVersion::findOrFail($a['shipment_version_id'])->inquiry_id, 'created_by' => User::factory()];
    }
}
