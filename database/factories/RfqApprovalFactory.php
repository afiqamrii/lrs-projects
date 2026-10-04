<?php

namespace Database\Factories;

use App\Models\RfqRevision;
use App\Models\User;
use App\Support\Processing;
use App\Support\RfqContent;
use Illuminate\Database\Eloquent\Factories\Factory;

class RfqApprovalFactory extends Factory
{
    public function definition(): array
    {
        return ['rfq_revision_id' => RfqRevision::factory(), 'snapshot' => fn (array $a): array => RfqContent::snapshot(RfqRevision::findOrFail($a['rfq_revision_id'])), 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'approved_by' => User::factory(), 'approver_name' => 'Synthetic approver', 'approved_at' => now()];
    }
}
