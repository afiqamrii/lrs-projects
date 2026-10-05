<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VendorConfirmation;
use App\Models\VendorReconfirmation;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VendorConfirmation> */
class VendorConfirmationFactory extends Factory
{
    /** Deliberately incomplete persistence fixtures. Accepted commercial stories must use reviewed workflow actions; child factories require their explicit source parent. */
    public function definition(): array
    {
        return ['vendor_reconfirmation_id' => fn (): int => VendorReconfirmation::latest('id')->firstOrFail()->id, 'inquiry_id' => fn (array $a): int => VendorReconfirmation::findOrFail($a['vendor_reconfirmation_id'])->inquiry_id, 'number' => 1, 'action_key' => (string) Str::uuid(), 'request_digest' => hash('sha256', 'pending fixture'), 'status' => 'pending', 'snapshot' => ['data' => ['notes' => 'Pending capacity fixture'], 'differences' => [], 'review_gaps' => ['Actual vendor evidence required']], 'digest' => fn (array $a): string => Processing::hash($a['snapshot']), 'confirmed_at' => now(), 'reviewed_by' => User::factory(), 'created_at' => now()];
    }
}
