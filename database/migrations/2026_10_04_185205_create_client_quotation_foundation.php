<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_quotations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->unique()->constrained()->restrictOnDelete();
            $t->string('reference')->unique();
            $t->unsignedInteger('current_number')->default(0);
            $t->timestampsTz();
        });
        Schema::create('client_quotation_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('client_quotation_id')->constrained()->restrictOnDelete();
            $t->foreignId('offer_selection_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('state');
            $t->jsonb('payload');
            $t->jsonb('pricing');
            $t->jsonb('source_snapshot');
            $t->string('pdf_path');
            $t->string('pdf_checksum', 64);
            $t->unsignedBigInteger('pdf_size');
            $t->string('digest', 64);
            $t->text('change_reason');
            $t->foreignId('resend_of_id')->nullable()->constrained('mail_dispatches')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('author_name');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['client_quotation_id', 'number']);
        });
        Schema::create('client_quotation_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('client_quotation_revision_id')->unique()->constrained()->restrictOnDelete();
            $t->text('snapshot');
            $t->string('digest', 64);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->string('reviewer_name');
            $t->timestampTz('approved_at');
        });
        Schema::table('mail_envelopes', function (Blueprint $t): void {
            $t->foreignId('client_quotation_approval_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK (num_nonnulls(rfq_approval_id, clarification_id, client_quotation_approval_id) = 1)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_quotation_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_TABLE_NAME = 'client_quotation_revisions' THEN
IF NOT EXISTS (SELECT 1 FROM client_quotations q JOIN offer_selections s ON s.inquiry_id=q.inquiry_id WHERE q.id=NEW.client_quotation_id AND s.id=NEW.offer_selection_id) THEN RAISE EXCEPTION 'Quotation cost basis lineage mismatch'; END IF;
IF NEW.resend_of_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mail_dispatches d JOIN mail_envelopes e ON e.id=d.mail_envelope_id JOIN client_quotations q ON q.inquiry_id=e.inquiry_id WHERE d.id=NEW.resend_of_id AND q.id=NEW.client_quotation_id AND e.client_quotation_approval_id IS NOT NULL) THEN RAISE EXCEPTION 'Resend lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME = 'mail_envelopes' AND NEW.client_quotation_approval_id IS NOT NULL THEN
IF NOT EXISTS (SELECT 1 FROM client_quotation_approvals a JOIN client_quotation_revisions r ON r.id=a.client_quotation_revision_id JOIN client_quotations q ON q.id=r.client_quotation_id WHERE a.id=NEW.client_quotation_approval_id AND q.inquiry_id=NEW.inquiry_id AND NEW.source_key='client_quote:' || a.id::text) THEN RAISE EXCEPTION 'Quotation envelope lineage mismatch'; END IF;
END IF; RETURN NEW; END $$;
CREATE TRIGGER quotation_revision_lineage BEFORE INSERT ON client_quotation_revisions FOR EACH ROW EXECUTE FUNCTION lrs_quotation_lineage();
CREATE TRIGGER quotation_revision_immutable BEFORE UPDATE OR DELETE ON client_quotation_revisions FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
CREATE TRIGGER quotation_approval_immutable BEFORE UPDATE OR DELETE ON client_quotation_approvals FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
DROP TRIGGER mail_envelope_lineage ON mail_envelopes;
CREATE OR REPLACE FUNCTION lrs_envelope_lineage_v7() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.client_quotation_approval_id IS NULL THEN
IF NEW.rfq_approval_id IS NOT NULL THEN
IF NOT EXISTS (SELECT 1 FROM rfq_approvals a JOIN rfq_revisions v ON v.id=a.rfq_revision_id JOIN rfqs r ON r.id=v.rfq_id WHERE a.id=NEW.rfq_approval_id AND r.inquiry_id=NEW.inquiry_id AND NEW.source_key='rfq:' || a.id::text) THEN RAISE EXCEPTION 'Invalid RFQ envelope lineage'; END IF;
ELSE
IF NOT EXISTS (SELECT 1 FROM clarifications c WHERE c.id=NEW.clarification_id AND c.inquiry_id=NEW.inquiry_id AND NEW.source_key='clarification:' || c.id::text) THEN RAISE EXCEPTION 'Invalid clarification envelope lineage'; END IF;
END IF;
ELSE
IF NOT EXISTS (SELECT 1 FROM client_quotation_approvals a JOIN client_quotation_revisions r ON r.id=a.client_quotation_revision_id JOIN client_quotations q ON q.id=r.client_quotation_id WHERE a.id=NEW.client_quotation_approval_id AND q.inquiry_id=NEW.inquiry_id AND NEW.source_key='client_quote:' || a.id::text) THEN RAISE EXCEPTION 'Invalid quotation envelope lineage'; END IF;
END IF; RETURN NEW; END $$;
CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_envelope_lineage_v7();
SQL);
    }

    public function down(): void
    {
        if (DB::table('mail_envelopes')->whereNotNull('client_quotation_approval_id')->exists()) {
            throw new RuntimeException('Cannot roll back quotation schema with approved outbox evidence. Preserve records and use a forward migration.');
        }
        DB::statement('DROP TRIGGER mail_envelope_lineage ON mail_envelopes');
        DB::statement('CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_mail_lineage()');
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->dropConstrainedForeignId('client_quotation_approval_id'));
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK ((rfq_approval_id IS NULL) <> (clarification_id IS NULL))');
        Schema::dropIfExists('client_quotation_approvals');
        Schema::dropIfExists('client_quotation_revisions');
        Schema::dropIfExists('client_quotations');
        DB::statement('DROP FUNCTION lrs_quotation_lineage()');
        DB::statement('DROP FUNCTION lrs_envelope_lineage_v7()');
    }
};
