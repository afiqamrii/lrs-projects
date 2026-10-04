<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InquiryCommunicationFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'author_id' => User::factory(), 'author_name' => 'Test staff', 'kind' => 'note', 'channel' => 'phone', 'occurred_at' => now(), 'notes' => 'Manually recorded test conversation.'];
    }
}
