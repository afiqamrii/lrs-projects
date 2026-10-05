<?php

namespace Database\Factories;

use App\Models\ClientQuotation;
use App\Models\ClientQuotationRevision;
use App\Models\OfferSelection;
use App\Models\User;
use App\Support\QuotationEligibility;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientQuotationRevisionFactory extends Factory
{
    /** Deliberately incomplete evidence for persistence/permission tests; commercial stories use ManageQuotation. */
    public function definition(): array
    {
        return ['offer_selection_id' => OfferSelection::factory(),
            'client_quotation_id' => fn (array $a): int => ClientQuotation::factory()->create(['inquiry_id' => OfferSelection::findOrFail($a['offer_selection_id'])->inquiry_id])->id,
            'number' => 1, 'state' => 'draft', 'payload' => ['company' => ['timezone' => 'Asia/Kuala_Lumpur'], 'to' => null, 'cc' => []],
            'pricing' => ['gaps' => ['Factory draft is not reviewed pricing']],
            'source_snapshot' => fn (array $a): array => OfferSelection::findOrFail($a['offer_selection_id'])->snapshot,
            'pdf_path' => 'fixtures/unavailable.pdf', 'pdf_checksum' => hash('sha256', 'fixture'), 'pdf_size' => 7,
            'change_reason' => 'Deliberately incomplete private evidence fixture', 'created_by' => User::factory(),
            'author_name' => 'Fixture staff', 'created_at' => now()->startOfSecond(),
            'digest' => fn (array $a): string => QuotationEligibility::digest(ClientQuotationRevision::make($a))];
    }
}
