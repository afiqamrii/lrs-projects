<?php

namespace Database\Factories;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublicSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'idempotency_hash' => hash('sha256', fake()->uuid()), 'session_hash' => hash('sha256', fake()->uuid()), 'received_at' => now(), 'snapshot' => ['contact' => ['name' => fake()->name(), 'email' => fake()->safeEmail()], 'shipment' => [], 'documents' => [], 'privacy' => ['acknowledged' => true, 'version' => 'test.1']]];
    }
}
