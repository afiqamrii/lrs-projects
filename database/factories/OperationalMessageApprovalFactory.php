<?php

namespace Database\Factories;

use App\Models\OperationalMessage;
use App\Models\OperationalMessageApproval;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperationalMessageApproval> */
class OperationalMessageApprovalFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['operational_message_id' => OperationalMessage::factory(), 'snapshot' => fn (array $a): array => ['message_digest' => OperationalMessage::findOrFail($a['operational_message_id'])->digest, 'fixture' => 'Incomplete evidence cannot authorize release'], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'approved_by' => User::factory(), 'approved_at' => now()];
    }
}
