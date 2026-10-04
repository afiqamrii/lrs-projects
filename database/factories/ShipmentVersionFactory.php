<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'number' => 1, 'snapshot' => fn (array $attributes): array => Inquiry::findOrFail($attributes['inquiry_id'])->snapshot(), 'snapshot_hash' => fn (array $attributes): string => Inquiry::findOrFail($attributes['inquiry_id'])->snapshotHash(), 'reviewer_id' => User::factory(), 'reviewer_name' => 'Test reviewer', 'confirmed_at' => now()];
    }
}
