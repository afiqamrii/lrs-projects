<?php

namespace Database\Factories;

use App\Models\FollowupAuthorization;
use App\Models\FollowupPlan;
use App\Models\FollowupPolicy;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowupAuthorization> */
class FollowupAuthorizationFactory extends Factory
{
    public function definition(): array
    {
        return ['rfq_approval_id' => RfqApproval::factory(), 'followup_plan_id' => function (array $a): int {
            $r = RfqApproval::findOrFail($a['rfq_approval_id'])->revision;

            return FollowupPlan::firstOrCreate(['target_key' => 'rfq:'.$r->rfq_id], ['kind' => 'rfq', 'inquiry_id' => $r->rfq->inquiry_id])->id;
        }, 'followup_policy_id' => FollowupPolicy::factory(), 'mode' => 'manual_review', 'snapshot' => ['factory_only' => true], 'digest' => fn (array $a) => Processing::hash($a['snapshot']), 'approved_by' => User::factory(), 'reason' => 'Synthetic storage fixture; not an executable authorization', 'created_at' => now()];
    }
}
