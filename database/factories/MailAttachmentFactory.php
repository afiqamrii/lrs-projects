<?php

namespace Database\Factories;

use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MailAttachment> */
class MailAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['source' => [], 'source_hash' => Processing::hash([]), 'mail_message_id' => MailMessage::factory(), 'provider_id' => (string) Str::uuid(), 'name' => 'synthetic.pdf', 'mime' => 'application/pdf', 'size' => 100, 'type' => '#microsoft.graph.fileAttachment', 'state' => 'pending'];
    }
}
