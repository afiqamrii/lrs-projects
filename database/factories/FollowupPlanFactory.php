<?php

namespace Database\Factories;

use App\Models\FollowupPlan;
use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowupPlan> */
class FollowupPlanFactory extends Factory
{
    public function definition(): array
    {
        return ['target_key' => 'rfq:factory-'.fake()->unique()->uuid(), 'inquiry_id' => Inquiry::factory(), 'kind' => 'rfq', 'state' => 'inactive'];
    }
}
