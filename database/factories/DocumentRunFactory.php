<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentRunFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'document_id' => fn (array $attributes): int => InquiryDocument::factory()->create(['inquiry_id' => $attributes['inquiry_id']])->id, 'requested_by' => User::factory(), 'identity' => fake()->sha256(), 'generation' => 1, 'checksum' => fake()->sha256(), 'configuration' => ['limits' => config('extraction'), 'tools' => []], 'selection' => ['pages' => [], 'ocr_pages' => []], 'state' => 'queued'];
    }
}
