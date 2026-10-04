<?php

namespace Database\Factories;

use App\Models\MailMessage;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MailMessage> */
class MailMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['mailbox_key' => 'synthetic-mailbox', 'provider_id' => (string) Str::uuid(), 'is_demo' => false, 'direction' => 'incoming', 'sender_email' => 'sender@example.test', 'subject' => 'Synthetic message', 'received_at' => now(), 'source' => ['body' => ['contentType' => 'Text', 'content' => 'Synthetic source evidence'], 'from' => ['emailAddress' => ['address' => 'sender@example.test']]], 'source_hash' => Processing::hash(['body' => ['contentType' => 'Text', 'content' => 'Synthetic source evidence'], 'from' => ['emailAddress' => ['address' => 'sender@example.test']]]), 'classification' => 'other', 'match_state' => 'unmatched'];
    }
}
