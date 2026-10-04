<?php

namespace Database\Factories;

use App\Models\RfqApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RfqDispatchFactory extends Factory
{
    public function definition(): array
    {
        return ['rfq_approval_id' => RfqApproval::factory(), 'action_key' => (string) Str::uuid(), 'recorded_by' => User::factory(), 'actor_name' => 'Synthetic recorder', 'sent_at' => now(), 'recorded_at' => now(), 'channel' => 'email', 'recipients' => fn (array $a): array => array_intersect_key(RfqApproval::findOrFail($a['rfq_approval_id'])->snapshot, array_flip(['to', 'cc'])), 'evidence' => 'Synthetic test declaration'];
    }
}
