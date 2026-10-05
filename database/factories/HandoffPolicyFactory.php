<?php

namespace Database\Factories;

use App\Models\HandoffPolicy;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HandoffPolicy> */
class HandoffPolicyFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['number' => 1, 'snapshot' => ['schema' => 'lrs-handoff-policy-1', 'freshness_hours' => null, 'require_po' => false, 'require_deposit' => false, 'required_documents' => [], 'operational_exceptions' => []], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'approved_by' => User::factory()->state(['role' => 'admin']), 'reason' => 'Isolated test workflow policy', 'created_at' => now()];
    }
}
