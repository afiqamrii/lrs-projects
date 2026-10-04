<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $t): void {
            $t->boolean('is_demo')->default(false)->index();
        });
        DB::statement("UPDATE inquiries SET is_demo = true WHERE public_contact->>'email' ILIKE '%.test' OR client_id IN (SELECT id FROM clients WHERE reference_identifier IN ('DEMO-PHASE-2', 'DEMO-PHASE-3B'))");
        Schema::create('mailbox_connections', function (Blueprint $t): void {
            $t->id();
            $t->string('state')->default('disconnected');
            $t->boolean('is_demo')->default(false);
            $t->uuid('tenant_id')->nullable();
            $t->uuid('account_id')->nullable();
            $t->string('account_email')->nullable();
            $t->uuid('target_id')->nullable();
            $t->string('target_email')->nullable();
            $t->string('target_name')->nullable();
            $t->string('mailbox_type')->default('personal');
            $t->string('send_mode')->default('send_as');
            $t->unsignedInteger('generation')->default(1);
            $t->string('identity_hash', 64)->nullable();
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->uuid('refresh_lease')->nullable();
            $t->timestampTz('refresh_until')->nullable();
            $t->timestampTz('import_from')->nullable();
            $t->unsignedBigInteger('transport_limit')->nullable();
            $t->boolean('rights_confirmed')->default(false);
            $t->boolean('account_sent_items')->default(false);
            $t->timestampTz('last_sync_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE mailbox_connections ADD CONSTRAINT company_mailbox_singleton CHECK (id = 1)');
        Schema::create('mail_oauth_attempts', function (Blueprint $t): void {
            $t->string('state_hash', 64)->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('session_hash', 64);
            $t->text('verifier');
            $t->unsignedInteger('generation');
            $t->timestampTz('expires_at');
            $t->timestampTz('used_at')->nullable();
        });
        Schema::create('mailbox_folders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('mailbox_connection_id')->constrained()->restrictOnDelete();
            $t->string('identity_hash', 64);
            $t->uuid('mailbox_id');
            $t->string('provider_id', 512);
            $t->string('name');
            $t->string('kind');
            $t->boolean('enabled')->default(true);
            $t->timestampTz('import_from');
            $t->text('cursor')->nullable();
            $t->text('page')->nullable();
            $t->unsignedInteger('offset')->default(0);
            $t->unsignedInteger('cycle')->default(0);
            $t->unsignedInteger('failure_count')->default(0);
            $t->unsignedInteger('cycle_count')->default(0);
            $t->unsignedInteger('resync_count')->default(0);
            $t->uuid('lease')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->timestampTz('last_sync_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampsTz();
            $t->unique(['identity_hash', 'mailbox_id', 'provider_id']);
            $t->index(['enabled', 'next_attempt_at']);
        });
        Schema::create('mail_messages', function (Blueprint $t): void {
            $t->id();
            $t->string('mailbox_key', 128);
            $t->string('provider_id', 512);
            $t->boolean('is_demo')->default(false);
            $t->string('direction');
            $t->string('internet_id', 998)->nullable()->index();
            $t->string('sender_email')->nullable()->index();
            $t->string('subject', 500);
            $t->timestampTz('received_at')->nullable();
            $t->text('source');
            $t->string('source_hash', 64);
            $t->string('classification')->default('other');
            $t->string('match_state')->default('unmatched')->index();
            $t->foreignId('inquiry_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('rfq_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->jsonb('candidates')->default('[]');
            $t->text('match_reason')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->string('attachment_state')->default('pending');
            $t->text('attachment_error')->nullable();
            $t->timestampTz('deleted_at_provider')->nullable();
            $t->timestampsTz();
            $t->unique(['mailbox_key', 'provider_id']);
            $t->index(['inquiry_id', 'received_at']);
            $t->index('rfq_revision_id');
        });
        Schema::create('mail_folder_message', function (Blueprint $t): void {
            $t->foreignId('mailbox_folder_id')->constrained()->restrictOnDelete();
            $t->foreignId('mail_message_id')->constrained()->restrictOnDelete();
            $t->timestampTz('removed_at')->nullable();
            $t->primary(['mailbox_folder_id', 'mail_message_id']);
            $t->index('mail_message_id');
        });
        Schema::create('mail_attachments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('mail_message_id')->constrained()->restrictOnDelete();
            $t->string('provider_id', 512);
            $t->text('source');
            $t->string('source_hash', 64);
            $t->string('name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('type');
            $t->boolean('is_inline')->default(false);
            $t->string('state')->default('pending');
            $t->string('storage_path')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->text('error')->nullable();
            $t->timestampsTz();
            $t->unique(['mail_message_id', 'provider_id']);
        });
        Schema::create('mail_attachment_documents', function (Blueprint $t): void {
            $t->foreignId('mail_attachment_id')->constrained()->restrictOnDelete();
            $t->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $t->foreignId('inquiry_document_id')->constrained()->restrictOnDelete();
            $t->primary(['mail_attachment_id', 'inquiry_id']);
            $t->index('inquiry_document_id');
            $t->index('inquiry_id');
        });
        Schema::create('mail_envelopes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('rfq_approval_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('clarification_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $t->string('source_key');
            $t->string('content_digest', 64);
            $t->string('identity_hash', 64);
            $t->text('snapshot');
            $t->string('digest', 64);
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->string('authorizer_name');
            $t->timestampTz('authorized_at');
            $t->unique(['source_key', 'identity_hash', 'digest', 'authorized_by'], 'mail_envelope_authorization_unique');
        });
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK ((rfq_approval_id IS NULL) <> (clarification_id IS NULL))');
        Schema::create('mail_dispatches', function (Blueprint $t): void {
            $t->id();
            $t->uuid('dispatch_key')->unique();
            $t->foreignId('mail_envelope_id')->constrained()->restrictOnDelete();
            $t->string('source_key');
            $t->string('status')->default('queued');
            $t->boolean('is_demo')->default(false);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('requested_at');
            $t->string('provider_draft_id', 512)->nullable();
            $t->string('internet_id', 998)->nullable()->index();
            $t->text('upload_state')->nullable();
            $t->uuid('lease')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('reconcile_attempts')->default(0);
            $t->timestampTz('draft_started_at')->nullable();
            $t->timestampTz('submission_started_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('observed_at')->nullable();
            $t->timestampTz('cancel_requested_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'next_attempt_at']);
        });
        DB::statement("CREATE UNIQUE INDEX mail_dispatch_one_release ON mail_dispatches (source_key) WHERE status <> 'cancelled'");
        Schema::create('mail_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('mail_dispatch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('mail_message_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('kind');
            $t->text('note');
            $t->timestampTz('created_at');
            $t->index('mail_dispatch_id');
            $t->index('mail_message_id');
            $t->index('actor_id');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_mail_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Mail evidence cannot be deleted'; END IF;
IF TG_ARGV[0] = 'all' THEN RAISE EXCEPTION 'Frozen mail evidence cannot be changed'; END IF;
IF TG_ARGV[0] = 'source' THEN
IF (NEW.source IS DISTINCT FROM OLD.source OR NEW.source_hash IS DISTINCT FROM OLD.source_hash OR NEW.provider_id IS DISTINCT FROM OLD.provider_id OR NEW.mailbox_key IS DISTINCT FROM OLD.mailbox_key OR NEW.direction IS DISTINCT FROM OLD.direction OR NEW.is_demo IS DISTINCT FROM OLD.is_demo OR NEW.sender_email IS DISTINCT FROM OLD.sender_email OR NEW.internet_id IS DISTINCT FROM OLD.internet_id OR NEW.subject IS DISTINCT FROM OLD.subject OR NEW.received_at IS DISTINCT FROM OLD.received_at) THEN RAISE EXCEPTION 'Frozen source evidence cannot be changed'; END IF;
END IF;
IF TG_ARGV[0] = 'attachment' THEN
IF NEW.source IS DISTINCT FROM OLD.source OR NEW.source_hash IS DISTINCT FROM OLD.source_hash OR NEW.mail_message_id IS DISTINCT FROM OLD.mail_message_id OR NEW.provider_id IS DISTINCT FROM OLD.provider_id THEN RAISE EXCEPTION 'Original attachment metadata is immutable'; END IF; END IF;
IF TG_ARGV[0] = 'dispatch' THEN
IF (NEW.mail_envelope_id IS DISTINCT FROM OLD.mail_envelope_id OR NEW.source_key IS DISTINCT FROM OLD.source_key OR NEW.dispatch_key IS DISTINCT FROM OLD.dispatch_key OR NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.requested_at IS DISTINCT FROM OLD.requested_at OR NEW.is_demo IS DISTINCT FROM OLD.is_demo OR (OLD.submission_started_at IS NOT NULL AND NEW.submission_started_at IS DISTINCT FROM OLD.submission_started_at)) THEN
RAISE EXCEPTION 'Frozen mail evidence cannot be changed';
END IF; END IF; RETURN NEW; END $$;
CREATE TRIGGER mail_envelope_immutable BEFORE UPDATE OR DELETE ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
CREATE TRIGGER mail_source_immutable BEFORE UPDATE OR DELETE ON mail_messages FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('source');
CREATE TRIGGER mail_dispatch_frozen BEFORE UPDATE OR DELETE ON mail_dispatches FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('dispatch');
CREATE TRIGGER mail_event_immutable BEFORE UPDATE OR DELETE ON mail_events FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
CREATE TRIGGER mail_attachment_original BEFORE UPDATE OR DELETE ON mail_attachments FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('attachment');
CREATE OR REPLACE FUNCTION lrs_mail_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE case_id bigint; source_id bigint;
BEGIN
IF TG_TABLE_NAME = 'mail_envelopes' THEN
IF NEW.rfq_approval_id IS NOT NULL THEN
SELECT r.inquiry_id INTO case_id FROM rfq_approvals a JOIN rfq_revisions v ON v.id=a.rfq_revision_id JOIN rfqs r ON r.id=v.rfq_id WHERE a.id=NEW.rfq_approval_id;
IF NEW.source_key <> 'rfq:' || NEW.rfq_approval_id::text THEN RAISE EXCEPTION 'Invalid approved source identity'; END IF;
ELSE
SELECT inquiry_id INTO case_id FROM clarifications WHERE id=NEW.clarification_id;
IF NEW.source_key <> 'clarification:' || NEW.clarification_id::text THEN RAISE EXCEPTION 'Invalid clarification source identity'; END IF;
END IF;
IF case_id IS DISTINCT FROM NEW.inquiry_id THEN RAISE EXCEPTION 'Envelope case lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME = 'mail_dispatches' THEN
IF NOT EXISTS (SELECT 1 FROM mail_envelopes WHERE id=NEW.mail_envelope_id AND source_key=NEW.source_key) THEN RAISE EXCEPTION 'Dispatch source lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME = 'mail_messages' THEN
IF NEW.rfq_revision_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM rfq_revisions v JOIN rfqs r ON r.id=v.rfq_id WHERE v.id=NEW.rfq_revision_id AND r.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Message request lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME = 'mail_attachment_documents' THEN
IF NOT EXISTS (SELECT 1 FROM inquiry_documents WHERE id=NEW.inquiry_document_id AND inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Document case lineage mismatch'; END IF;
END IF; RETURN NEW; END $$;
CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_mail_lineage();
CREATE TRIGGER mail_dispatch_lineage BEFORE INSERT ON mail_dispatches FOR EACH ROW EXECUTE FUNCTION lrs_mail_lineage();
CREATE TRIGGER mail_message_lineage BEFORE INSERT OR UPDATE ON mail_messages FOR EACH ROW EXECUTE FUNCTION lrs_mail_lineage();
CREATE TRIGGER mail_attachment_lineage BEFORE INSERT OR UPDATE ON mail_attachment_documents FOR EACH ROW EXECUTE FUNCTION lrs_mail_lineage();
CREATE OR REPLACE FUNCTION lrs_clarification_approved() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_OP = 'DELETE' AND OLD.approved_at IS NOT NULL THEN RAISE EXCEPTION 'Approved clarification evidence cannot be deleted'; END IF;
IF TG_OP = 'UPDATE' AND OLD.approved_at IS NOT NULL THEN
IF NEW.body IS DISTINCT FROM OLD.body OR NEW.recipient_email IS DISTINCT FROM OLD.recipient_email OR NEW.client_contact_id IS DISTINCT FROM OLD.client_contact_id OR NEW.shipment_hash IS DISTINCT FROM OLD.shipment_hash OR NEW.shipment_revision IS DISTINCT FROM OLD.shipment_revision OR NEW.inquiry_id IS DISTINCT FROM OLD.inquiry_id OR NEW.approved_at IS DISTINCT FROM OLD.approved_at OR NEW.approved_by IS DISTINCT FROM OLD.approved_by THEN RAISE EXCEPTION 'Approved clarification content is immutable'; END IF;
END IF;
IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END $$;
CREATE TRIGGER clarification_approved_mail_guard BEFORE UPDATE OR DELETE ON clarifications FOR EACH ROW EXECUTE FUNCTION lrs_clarification_approved();

SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS clarification_approved_mail_guard ON clarifications');
        foreach (['mail_events', 'mail_dispatches', 'mail_envelopes', 'mail_attachment_documents', 'mail_attachments', 'mail_folder_message', 'mail_messages', 'mailbox_folders', 'mail_oauth_attempts', 'mailbox_connections'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS lrs_mail_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS lrs_mail_lineage()');
        DB::statement('DROP FUNCTION IF EXISTS lrs_clarification_approved()');
        Schema::table('inquiries', fn (Blueprint $t) => $t->dropColumn('is_demo'));
    }
};
