<?php

namespace Database\Factories;

use App\Models\ClientContact;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClarificationFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'client_contact_id' => ClientContact::factory(), 'recipient_email' => 'contact@example.test', 'body' => 'Please confirm your route.', 'shipment_revision' => 1, 'shipment_hash' => hash('sha256', '[]'), 'status' => 'draft'];
    }
}
