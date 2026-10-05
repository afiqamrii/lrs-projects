<?php

namespace Database\Factories;

use App\Models\HandoffApproval;
use App\Models\HandoffRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HandoffApproval> */
class HandoffApprovalFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['handoff_revision_id' => fn (): int => HandoffRevision::where('state', 'ready')->latest('id')->firstOrFail()->id, 'snapshot' => fn (array $a): array => ['revision_digest' => HandoffRevision::findOrFail($a['handoff_revision_id'])->digest, 'fixture' => 'Incomplete source fixture cannot authorize release'], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'pdf_path' => 'fixtures/unavailable-approved-handoff.pdf', 'pdf_checksum' => hash('sha256', 'fixture'), 'pdf_size' => 7, 'approved_by' => User::factory(), 'approved_at' => now()];
    }
}
