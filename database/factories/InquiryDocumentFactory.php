<?php

namespace Database\Factories;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InquiryDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['inquiry_id' => Inquiry::factory(), 'uploader_id' => User::factory(), 'uploader_name' => 'Test staff', 'original_name' => 'document.pdf', 'storage_path' => 'test/document.pdf', 'mime' => 'application/pdf', 'size' => 100, 'checksum' => fake()->sha256(), 'classification' => 'other'];
    }
}
