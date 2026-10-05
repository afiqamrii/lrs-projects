<?php

namespace Database\Factories;

use App\Actions\ManageFollowups;
use App\Models\FollowupPolicy;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowupPolicy> */
class FollowupPolicyFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'rfq', 'number' => fn (array $a) => (FollowupPolicy::where('kind', $a['kind'])->max('number') ?? 0) + 1, 'enabled' => false, 'snapshot' => fn (array $a) => ManageFollowups::defaults($a['kind']), 'digest' => fn (array $a) => Processing::hash($a['snapshot']), 'approved_by' => User::factory()->state(['role' => 'admin']), 'reason' => 'Synthetic disabled policy defaults', 'created_at' => now()];
    }
}
