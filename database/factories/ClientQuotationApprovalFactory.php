<?php

namespace Database\Factories;

use App\Models\ClientQuotationRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientQuotationApprovalFactory extends Factory
{
    /** Persistence fixture only. Runtime approval must always go through ManageQuotation. */
    public function definition(): array
    {
        return ['client_quotation_revision_id' => ClientQuotationRevision::factory(),
            'snapshot' => ['schema' => 'unreviewed-test-evidence'], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']),
            'approved_by' => User::factory(), 'reviewer_name' => 'Fixture reviewer', 'approved_at' => now()];
    }
}
