<?php

namespace Database\Factories;

use App\Models\FollowupAuthorization;
use App\Models\FollowupStage;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowupStage> */
class FollowupStageFactory extends Factory
{
    public function definition(): array
    {
        return ['followup_authorization_id' => FollowupAuthorization::factory(), 'followup_plan_id' => fn (array $a) => FollowupAuthorization::findOrFail($a['followup_authorization_id'])->followup_plan_id, 'sequence' => 1, 'ordinal' => 1, 'state' => 'needs_review', 'due_at' => now()->addDays(2), 'content' => ['subject' => 'Synthetic reminder', 'body' => 'Synthetic storage fixture; not executable.', 'manifest' => []], 'digest' => fn (array $a) => Processing::hash($a['content'])];
    }
}
