<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientContactFactory extends Factory
{
    public function definition(): array
    {
        return ['client_id' => Client::factory(), 'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'is_active' => true, 'is_primary' => false];
    }
}
