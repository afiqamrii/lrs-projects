<?php

namespace Database\Factories;

use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MailboxFolder> */
class MailboxFolderFactory extends Factory
{
    public function definition(): array
    {
        return ['mailbox_connection_id' => MailboxConnection::current()->id, 'identity_hash' => MailboxConnection::current()->identity_hash ?? hash('sha256', 'synthetic-identity'), 'mailbox_id' => MailboxConnection::current()->target_id ?? '22222222-2222-4222-8222-222222222222', 'provider_id' => 'inbox', 'name' => 'Synthetic Inbox', 'kind' => 'incoming', 'import_from' => now()->subDay()];
    }
}
