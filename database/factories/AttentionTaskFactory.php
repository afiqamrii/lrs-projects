<?php

namespace Database\Factories;

use App\Models\AttentionTask;
use App\Models\FollowupPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttentionTask> */
class AttentionTaskFactory extends Factory
{
    public function definition(): array
    {
        return ['dedup_key' => fake()->unique()->uuid(), 'followup_plan_id' => FollowupPlan::factory(), 'inquiry_id' => fn (array $a) => FollowupPlan::findOrFail($a['followup_plan_id'])->inquiry_id, 'owner_id' => User::factory(), 'kind' => 'manual_review', 'title' => 'Review the original reply', 'next_action' => 'Associate exact source evidence before approving further communication.'];
    }
}
