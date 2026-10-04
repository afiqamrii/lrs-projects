<?php

namespace Database\Factories;

use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MailDispatch> */
class MailDispatchFactory extends Factory
{
    public function definition(): array
    {
        return ['dispatch_key' => (string) Str::uuid(), 'mail_envelope_id' => MailEnvelope::factory(), 'source_key' => fn (array $a): string => MailEnvelope::findOrFail($a['mail_envelope_id'])->source_key, 'status' => 'queued', 'is_demo' => false, 'requested_by' => User::factory(), 'requested_at' => now()];
    }
}
