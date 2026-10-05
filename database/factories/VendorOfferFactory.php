<?php

namespace Database\Factories;

use App\Models\RfqRevision;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorOfferFactory extends Factory
{
    public function definition(): array
    {
        $source = ['kind' => 'manual', 'text' => 'Fictional vendor quotation evidence', 'documents' => [], 'association_confirmed' => true, 'association_reason' => 'Factory commercial note'];

        return ['rfq_revision_id' => RfqRevision::factory(), 'inquiry_id' => fn (array $a): int => RfqRevision::findOrFail($a['rfq_revision_id'])->rfq->inquiry_id, 'vendor_id' => fn (array $a): int => RfqRevision::findOrFail($a['rfq_revision_id'])->rfq->vendor_id, 'shipment_version_id' => fn (array $a): int => RfqRevision::findOrFail($a['rfq_revision_id'])->rfq->round->shipment_version_id, 'sourcing_round_id' => fn (array $a): int => RfqRevision::findOrFail($a['rfq_revision_id'])->rfq->sourcing_round_id, 'alternative' => 'Standard sailing', 'source' => $source, 'source_identity' => Processing::hash($source), 'current_number' => 0, 'created_by' => User::factory()];
    }
}
