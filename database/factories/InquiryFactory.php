<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use App\Support\InquiryWorkflow;
use App\Support\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class InquiryFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => InquiryWorkflow::reference(), 'client_id' => Client::factory(), 'owner_id' => User::factory(), 'title' => 'Local shipment inquiry', 'priority' => 'normal', 'status' => 'draft', 'received_at' => now(), 'source_channel' => 'email', 'shipment' => Shipment::normalize([]), 'shipment_revision' => 1, 'lock_version' => 0];
    }
}
