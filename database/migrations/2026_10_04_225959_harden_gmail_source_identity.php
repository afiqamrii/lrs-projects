<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mail_messages ALTER COLUMN mailbox_key TYPE varchar(512)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_mail_connection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.mailbox_connection_id IS DISTINCT FROM OLD.mailbox_connection_id THEN RAISE EXCEPTION 'Original mailbox provenance is immutable'; END IF;
IF OLD.evidence_digest IS NOT NULL AND NEW.evidence_digest IS DISTINCT FROM OLD.evidence_digest THEN RAISE EXCEPTION 'Original mail content evidence is immutable'; END IF;
RETURN NEW; END $$;
SQL);
    }

    public function down(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Preserve multi-provider evidence; populated installations require a planned forward migration.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_mail_connection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.mailbox_connection_id IS DISTINCT FROM OLD.mailbox_connection_id THEN RAISE EXCEPTION 'Original mailbox provenance is immutable'; END IF;
RETURN NEW; END $$;
SQL);
        DB::statement('ALTER TABLE mail_messages ALTER COLUMN mailbox_key TYPE varchar(255)');
    }
};
