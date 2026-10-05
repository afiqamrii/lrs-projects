<?php

namespace Database\Factories;

use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HandoffEvent> */
class HandoffEventFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['handoff_approval_id' => HandoffApproval::factory(), 'inquiry_id' => fn (array $a): int => HandoffApproval::findOrFail($a['handoff_approval_id'])->revision->handoff->inquiry_id, 'action_key' => (string) Str::uuid(), 'request_digest' => hash('sha256', 'isolated event fixture'), 'kind' => 'handed_to_operations', 'snapshot' => ['data' => ['notes' => 'Test-only event; incomplete approval does not permit release']], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'occurred_at' => now(), 'recorded_by' => User::factory(), 'created_at' => now()];
    }
}
