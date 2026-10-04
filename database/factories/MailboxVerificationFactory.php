<?php

namespace Database\Factories;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

class MailboxVerificationFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'email' => fake()->safeEmail(), 'transport_state' => 'disabled', 'template_body' => 'We received your request.'];
    }
}
