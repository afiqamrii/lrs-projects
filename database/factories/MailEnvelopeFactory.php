<?php

namespace Database\Factories;

use App\Models\Clarification;
use App\Models\MailEnvelope;
use App\Models\RfqApproval;
use App\Models\User;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MailEnvelope> */
class MailEnvelopeFactory extends Factory
{
    public function definition(): array
    {
        return ['rfq_approval_id' => null, 'clarification_id' => Clarification::factory(), 'inquiry_id' => fn (array $a): int => $a['rfq_approval_id'] ? RfqApproval::findOrFail($a['rfq_approval_id'])->revision->rfq->inquiry_id : Clarification::findOrFail($a['clarification_id'])->inquiry_id, 'source_key' => fn (array $a): string => $a['rfq_approval_id'] ? 'rfq:'.$a['rfq_approval_id'] : 'clarification:'.$a['clarification_id'], 'content_digest' => hash('sha256', 'content'), 'identity_hash' => hash('sha256', 'synthetic-identity'), 'snapshot' => ['content' => ['body' => 'Synthetic evidence'], 'envelope' => ['is_demo' => false]], 'digest' => Processing::hash(['content' => ['body' => 'Synthetic evidence'], 'envelope' => ['is_demo' => false]]), 'authorized_by' => User::factory(), 'authorizer_name' => 'Synthetic agent', 'authorized_at' => now()];
    }
}
