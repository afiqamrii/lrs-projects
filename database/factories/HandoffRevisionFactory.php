<?php

namespace Database\Factories;

use App\Models\BookingHandoff;
use App\Models\HandoffRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HandoffRevision> */
class HandoffRevisionFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['booking_handoff_id' => BookingHandoff::factory(), 'number' => 1, 'state' => 'incomplete', 'snapshot' => ['evidence' => [], 'reason' => 'Incomplete test-only handoff'], 'dependency_digest' => hash('sha256', 'incomplete'), 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'pdf_path' => 'fixtures/unavailable-private-handoff.pdf', 'pdf_checksum' => hash('sha256', 'fixture'), 'pdf_size' => 7, 'created_by' => User::factory(), 'created_at' => now()];
    }
}
