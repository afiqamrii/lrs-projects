<?php

namespace Database\Seeders;

use App\Models\MailboxConnection;
use Illuminate\Database\Seeder;

class MailboxSeeder extends Seeder
{
    public function run(): void
    {
        MailboxConnection::current();
    }
}
