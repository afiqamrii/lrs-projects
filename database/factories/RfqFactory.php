<?php

namespace Database\Factories;

use App\Models\SourcingRound;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class RfqFactory extends Factory
{
    public function definition(): array
    {
        return ['sourcing_round_id' => SourcingRound::factory(), 'inquiry_id' => fn (array $a): int => SourcingRound::findOrFail($a['sourcing_round_id'])->inquiry_id, 'vendor_id' => Vendor::factory(), 'reference' => 'RFQ-SYNTHETIC-'.fake()->unique()->numerify('########'), 'current_number' => 1];
    }
}
