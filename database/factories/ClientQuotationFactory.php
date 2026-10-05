<?php

namespace Database\Factories;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientQuotationFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'reference' => 'FIXTURE-Q-'.fake()->unique()->numerify('######'), 'current_number' => 0];
    }
}
