<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiRunFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'requested_by' => User::factory(), 'identity' => fake()->sha256(), 'generation' => 1,
            'shipment_hash' => fn (array $attributes): string => Inquiry::findOrFail($attributes['inquiry_id'])->snapshotHash(),
            'shipment_revision' => 1, 'model' => 'synthetic-fixture', 'prompt_version' => config('ai.prompt_version'), 'schema_version' => config('ai.schema_version'),
            'settings' => [], 'sources' => [], 'working_snapshot' => fn (array $attributes): array => Inquiry::findOrFail($attributes['inquiry_id'])->snapshot(),
            'input_characters' => 0, 'input_bound' => 0, 'is_demo' => true, 'state' => 'queued'];
    }
}
