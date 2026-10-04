<?php

namespace Database\Factories;

use App\Models\Rfq;
use App\Models\User;
use App\Support\RfqContent;
use Illuminate\Database\Eloquent\Factories\Factory;

class RfqRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['rfq_id' => Rfq::factory(), 'number' => 1, 'status' => 'draft', 'payload' => fn (array $a): array => RfqContent::defaults(Rfq::findOrFail($a['rfq_id'])), 'created_by' => User::factory()];
    }
}
