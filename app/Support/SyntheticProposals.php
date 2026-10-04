<?php

namespace App\Support;

use App\Models\AiRun;
use App\Models\Inquiry;
use App\Models\User;

class SyntheticProposals
{
    public function create(Inquiry $inquiry, User $staff): AiRun
    {
        if (! app()->environment('local', 'testing') || ! config('extraction.demo') || ! str_contains($inquiry->title, 'Synthetic Demo Phase 3B')) {
            Processing::fail('Synthetic proposals are restricted to the explicitly labeled local demo inquiry.');
        }
        $catalogue = AiSources::catalogue($inquiry);
        $digital = collect($catalogue)->first(fn (array $source): bool => str_starts_with($source['label'], 'digital-packing-list.pdf') && $source['locator'] === 'page-1');
        $conflicting = collect($catalogue)->first(fn (array $source): bool => str_starts_with($source['label'], 'shipment.docx') && $source['locator'] === 'table-1-row-3');
        if (! $digital || ! $conflicting) {
            Processing::fail('Complete real local extraction of the demo digital PDF and DOCX before creating synthetic proposals.');
        }
        $sources = [$digital, $conflicting];
        $candidate = fn (string $field, mixed $value, string $quote, bool $ambiguous = false, ?array $source = null): array => ['field' => $field, 'value' => $value, 'raw_value' => $quote, 'evidence' => [['source_id' => ($source ?? $digital)['id'], 'locator' => ($source ?? $digital)['locator'], 'quote' => $quote]], 'ambiguous' => $ambiguous, 'warnings' => []];
        $result = [
            'candidates' => [
                $candidate('cargo_description', 'General machine parts', 'Cargo: General machine parts'),
                $candidate('origin_country', 'Malaysia', 'Origin: Port Klang, Malaysia'),
                $candidate('origin_location', 'Port Klang', 'Origin: Port Klang, Malaysia'),
                $candidate('destination_country', 'Singapore', 'Destination: Singapore port, Singapore'),
                $candidate('destination_location', 'Singapore port', 'Destination: Singapore port, Singapore'),
                $candidate('mode', 'LCL', 'Mode: LCL'),
                $candidate('scope', 'port_to_port', 'Scope: port to port'),
                $candidate('cargo_ready_date', '2026-10-10', 'Cargo ready: 2026-10-10'),
                $candidate('packages', [['packaging_type' => 'pallets', 'quantity' => '2', 'gross_weight' => '250.5', 'weight_unit' => 'kg', 'length' => '100', 'width' => '80', 'height' => '90', 'dimension_unit' => 'cm']], "Package group 1: 2 pallets\nGroup total gross weight: 250.5 kg\nPer-package dimensions: 100 x 80 x 90 cm"),
                $candidate('packages', [['packaging_type' => 'pallets', 'quantity' => '3', 'gross_weight' => null, 'weight_unit' => null, 'length' => null, 'width' => null, 'height' => null, 'dimension_unit' => null]], '3 pallets | unknown units | Arrival 10/11/26', true, $conflicting),
                $candidate('goods_value', '12000', 'Goods invoice value: USD 12000'),
                $candidate('goods_currency', 'USD', 'Goods invoice value: USD 12000'),
                $candidate('reference_quote', '450', 'Reference freight quote: USD 450'),
                $candidate('reference_currency', 'USD', 'Reference freight quote: USD 450'),
            ],
            'summary' => 'UNREVIEWED SYNTHETIC FIXTURE: general machine parts, Port Klang to Singapore, LCL. The packing list has 2 pallets, 250.5 kg row-total gross weight and per-package dimensions 100 × 80 × 90 cm. A separate Word table says 3 pallets with unknown units and an ambiguous arrival date. Compare both. Goods invoice value and freight reference price remain distinct. Identity and shipment readiness need staff confirmation.',
            'conflicts' => ['Packing list: 2 pallets; Word table: 3 pallets. Neither overrides the other automatically.'],
            'missing' => ['Ambiguous requested arrival date and unknown measurements in the conflicting Word row.'],
            'identity_flags' => ['No source establishes verified customer identity.'],
        ];
        $identity = Processing::hash(['demo' => 'synthetic-1', 'sources' => $sources, 'shipment' => $inquiry->snapshotHash(), 'result' => $result]);
        $previous = AiRun::where('inquiry_id', $inquiry->id)->where('identity', $identity)->first();
        if ($previous) {
            return $previous;
        }
        $run = AiRun::create(['inquiry_id' => $inquiry->id, 'requested_by' => $staff->id, 'identity' => $identity, 'generation' => 1, 'shipment_hash' => $inquiry->snapshotHash(), 'shipment_revision' => $inquiry->shipment_revision, 'model' => 'synthetic-fixture', 'prompt_version' => config('ai.prompt_version'), 'schema_version' => config('ai.schema_version'), 'settings' => [], 'sources' => $sources, 'working_snapshot' => $inquiry->snapshot(), 'input_characters' => mb_strlen(AiSources::input($sources)), 'input_bound' => AiUsage::bound($sources), 'state' => 'processing', 'is_demo' => true, 'started_at' => now()]);
        $run->update(['state' => 'needs_review', 'result' => $result, 'proposals' => ProposalEvidence::inspect($run, $result), 'completed_at' => now()]);

        return $run;
    }
}
