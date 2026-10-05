<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mail_messages ALTER COLUMN mailbox_connection_id DROP DEFAULT, ALTER COLUMN mailbox_connection_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Preserve multi-provider evidence; populated installations require a planned forward migration.');
        }
        DB::statement('ALTER TABLE mail_messages ALTER COLUMN mailbox_connection_id SET DEFAULT 1');
        if (! DB::table('mail_messages')->whereNull('mailbox_connection_id')->exists()) {
            DB::statement('ALTER TABLE mail_messages ALTER COLUMN mailbox_connection_id SET NOT NULL');
        }
    }
};
