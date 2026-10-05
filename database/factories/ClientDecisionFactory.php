<?php

namespace Database\Factories;

use App\Models\ClientDecision;
use App\Models\ClientQuotationRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ClientDecision> */
class ClientDecisionFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['client_quotation_revision_id' => ClientQuotationRevision::factory(), 'inquiry_id' => fn (array $a): int => ClientQuotationRevision::findOrFail($a['client_quotation_revision_id'])->quotation->inquiry_id, 'number' => 1, 'action_key' => (string) Str::uuid(), 'request_digest' => hash('sha256', 'incomplete fixture'), 'requested_outcome' => 'accepted', 'outcome' => 'review_required', 'channel' => 'phone', 'snapshot' => ['notes' => 'Incomplete identity and scope fixture; no acceptance authorized.', 'blocking_reasons' => ['Unreviewed fixture']], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'decided_at' => now(), 'reviewed_by' => User::factory(), 'created_at' => now()];
    }
}
