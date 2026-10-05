<?php

namespace Database\Factories;

use App\Models\OperationalMessage;
use App\Models\User;
use App\Models\VendorReconfirmation;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperationalMessage> */
class OperationalMessageFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['vendor_reconfirmation_id' => fn (): int => VendorReconfirmation::latest('id')->firstOrFail()->id, 'inquiry_id' => fn (array $a): int => VendorReconfirmation::findOrFail($a['vendor_reconfirmation_id'])->inquiry_id, 'number' => 1, 'kind' => 'reconfirmation', 'content' => ['subject' => 'Test-only incomplete message', 'body' => 'Pending exact review', 'manifest' => []], 'dependency_digest' => hash('sha256', 'incomplete'), 'digest' => fn (array $a): string => Processing::hash($a['content']), 'reason' => 'Unapproved isolated fixture', 'created_by' => User::factory(), 'created_at' => now()];
    }
}
