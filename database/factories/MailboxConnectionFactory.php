<?php

namespace Database\Factories;

use App\Models\MailboxConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MailboxConnection> */
class MailboxConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'state' => 'connected', 'is_demo' => false, 'tenant_id' => '11111111-1111-4111-8111-111111111111', 'account_id' => '22222222-2222-4222-8222-222222222222', 'target_id' => '22222222-2222-4222-8222-222222222222', 'account_email' => 'operations@example.test', 'target_email' => 'operations@example.test', 'target_name' => 'Synthetic operations', 'mailbox_type' => 'personal', 'send_mode' => 'send_as', 'generation' => 1, 'identity_hash' => hash('sha256', 'synthetic-identity'), 'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addHour(), 'import_from' => now()->subDay(), 'transport_limit' => 26214400, 'rights_confirmed' => true];
    }

    public function gmail(): static
    {
        return $this->state(fn () => ['id' => 2, 'provider' => 'gmail', 'tenant_id' => 'google', 'account_id' => 'opaque-google-subject-2', 'target_id' => 'opaque-google-subject-2', 'google_subject' => 'opaque-google-subject-2', 'account_email' => 'operations.gmail@meridian-logistics.example', 'target_email' => 'operations.gmail@meridian-logistics.example', 'from_alias' => 'operations.gmail@meridian-logistics.example', 'target_name' => 'Meridian Logistics Gmail', 'transport_limit' => 33000000, 'aliases' => [['sendAsEmail' => 'operations.gmail@meridian-logistics.example', 'verificationStatus' => 'accepted', 'isPrimary' => true]], 'aliases_checked_at' => now()]);
    }
}
