<?php

namespace Database\Factories;

use App\Models\AiRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProposalReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['ai_run_id' => AiRun::factory(), 'reviewer_id' => User::factory(), 'action_key' => fake()->uuid(), 'decision_hash' => fake()->sha256(), 'decisions' => [], 'before_values' => [], 'after_values' => [], 'resulting_revision' => 1, 'reviewed_at' => now()];
    }
}
