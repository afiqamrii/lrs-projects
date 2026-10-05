<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mailbox_connections DROP CONSTRAINT company_mailbox_singleton');
        foreach (['tenant_id', 'account_id', 'target_id'] as $column) {
            DB::statement("ALTER TABLE mailbox_connections ALTER COLUMN {$column} TYPE varchar(255) USING {$column}::text");
        }
        DB::statement('ALTER TABLE mailbox_folders ALTER COLUMN mailbox_id TYPE varchar(255) USING mailbox_id::text');
        Schema::table('mailbox_connections', function (Blueprint $t): void {
            $t->string('provider')->default('outlook');
            $t->boolean('incoming_enabled')->default(true);
            $t->string('google_subject')->nullable();
            $t->string('from_alias')->nullable();
            $t->text('aliases')->nullable();
            $t->text('granted_scopes')->nullable();
            $t->timestampTz('aliases_checked_at')->nullable();
        });
        DB::statement("ALTER TABLE mailbox_connections ADD CONSTRAINT mail_provider_check CHECK (provider IN ('outlook','gmail'))");
        DB::statement("CREATE UNIQUE INDEX google_mailbox_identity_unique ON mailbox_connections (google_subject,is_demo) WHERE provider='gmail' AND google_subject IS NOT NULL");
        Schema::table('company_settings', fn (Blueprint $t) => $t->foreignId('outbound_mailbox_id')->nullable()->constrained('mailbox_connections')->restrictOnDelete());
        Schema::table('mail_oauth_attempts', function (Blueprint $t): void {
            $t->foreignId('mailbox_connection_id')->default(1)->constrained()->restrictOnDelete();
            $t->string('provider')->default('outlook');
        });
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->foreignId('mailbox_connection_id')->default(1)->constrained()->restrictOnDelete());
        Schema::table('mail_messages', function (Blueprint $t): void {
            $t->foreignId('mailbox_connection_id')->default(1)->constrained()->restrictOnDelete();
            $t->foreignId('duplicate_of_id')->nullable()->constrained('mail_messages')->restrictOnDelete();
            $t->string('evidence_digest', 64)->nullable()->index();
        });
        Schema::table('mail_dispatches', function (Blueprint $t): void {
            $t->string('provider_draft_message_id', 512)->nullable();
            $t->string('provider_sent_id', 512)->nullable();
            $t->string('provider_thread_id', 512)->nullable();
        });
        Schema::table('mailbox_folders', function (Blueprint $t): void {
            $t->string('gmail_history_id', 255)->nullable();
            $t->string('gmail_initial_anchor', 255)->nullable();
            $t->string('gmail_phase')->default('initial');
            $t->text('gmail_page_token')->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_mail_connection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.mailbox_connection_id IS DISTINCT FROM OLD.mailbox_connection_id THEN RAISE EXCEPTION 'Original mailbox provenance is immutable'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER mail_message_connection_guard BEFORE UPDATE ON mail_messages FOR EACH ROW EXECUTE FUNCTION lrs_mail_connection_guard();
SQL);
        DB::statement("SELECT setval(pg_get_serial_sequence('mailbox_connections','id'), GREATEST((SELECT COALESCE(MAX(id),1) FROM mailbox_connections),1), true)");
    }

    public function down(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Preserve multi-provider evidence; populated installations require a planned forward migration.');
        }
        DB::statement('DROP TRIGGER mail_message_connection_guard ON mail_messages');
        DB::statement('DROP FUNCTION lrs_mail_connection_guard()');
        Schema::table('mail_dispatches', fn (Blueprint $t) => $t->dropColumn(['provider_draft_message_id', 'provider_sent_id', 'provider_thread_id']));
        Schema::table('mailbox_folders', fn (Blueprint $t) => $t->dropColumn(['gmail_history_id', 'gmail_initial_anchor', 'gmail_phase', 'gmail_page_token']));
        Schema::table('mail_messages', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('mailbox_connection_id');
            $t->dropConstrainedForeignId('duplicate_of_id');
            $t->dropColumn('evidence_digest');
        });
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->dropConstrainedForeignId('mailbox_connection_id'));
        Schema::table('mail_oauth_attempts', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('mailbox_connection_id');
            $t->dropColumn('provider');
        });
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropConstrainedForeignId('outbound_mailbox_id'));
        DB::statement('DROP INDEX google_mailbox_identity_unique');
        DB::statement('ALTER TABLE mailbox_connections DROP CONSTRAINT mail_provider_check');
        Schema::table('mailbox_connections', fn (Blueprint $t) => $t->dropColumn(['provider', 'incoming_enabled', 'google_subject', 'from_alias', 'aliases', 'granted_scopes', 'aliases_checked_at']));
        foreach (['tenant_id', 'account_id', 'target_id'] as $column) {
            DB::statement("ALTER TABLE mailbox_connections ALTER COLUMN {$column} TYPE uuid USING {$column}::uuid");
        }
        DB::statement('ALTER TABLE mailbox_folders ALTER COLUMN mailbox_id TYPE uuid USING mailbox_id::uuid');
        DB::statement('ALTER TABLE mailbox_connections ADD CONSTRAINT company_mailbox_singleton CHECK (id=1)');
    }
};
