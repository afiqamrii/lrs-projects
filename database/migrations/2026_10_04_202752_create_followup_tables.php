<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('followup_policies', function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 24);
            $t->unsignedInteger('number');
            $t->boolean('enabled')->default(false);
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['kind', 'number']);
        });
        Schema::create('followup_plans', function (Blueprint $t): void {
            $t->id();
            $t->string('target_key')->unique();
            $t->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $t->string('kind', 24);
            $t->unsignedBigInteger('authorization_id')->nullable();
            $t->string('state', 24)->default('inactive');
            $t->unsignedInteger('send_count')->default(0);
            $t->timestampTz('next_due_at')->nullable();
            $t->timestampTz('fixture_at')->nullable();
            $t->text('reason')->nullable();
            $t->timestampsTz();
        });
        Schema::create('followup_authorizations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('followup_plan_id')->constrained()->restrictOnDelete();
            $t->foreignId('followup_policy_id')->constrained()->restrictOnDelete();
            $t->foreignId('rfq_approval_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('client_quotation_approval_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('mode', 24);
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestampTz('created_at');
        });
        Schema::table('followup_plans', fn (Blueprint $t) => $t->foreign('authorization_id')->references('id')->on('followup_authorizations')->restrictOnDelete());
        Schema::create('followup_stages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('followup_plan_id')->constrained()->restrictOnDelete();
            $t->foreignId('followup_authorization_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->unsignedInteger('ordinal');
            $t->string('state', 24)->default('needs_review');
            $t->timestampTz('due_at');
            $t->text('content');
            $t->char('digest', 64);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampTz('counted_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->unique(['followup_plan_id', 'sequence']);
        });
        DB::statement("CREATE UNIQUE INDEX followup_one_pending ON followup_stages (followup_plan_id) WHERE state IN ('needs_review','queued','uncertain')");
        DB::statement("CREATE INDEX followup_due ON followup_plans (next_due_at,id) WHERE state = 'active'");
        Schema::create('attention_tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('dedup_key')->unique();
            $t->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $t->foreignId('followup_plan_id')->constrained()->restrictOnDelete();
            $t->foreignId('mail_message_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('kind', 48);
            $t->string('title');
            $t->text('next_action');
            $t->string('state', 24)->default('open');
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('resolution')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->index(['state', 'owner_id']);
        });
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->foreignId('followup_stage_id')->nullable()->constrained()->restrictOnDelete());
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK (num_nonnulls(rfq_approval_id, clarification_id, client_quotation_approval_id, followup_stage_id) = 1)');
        Schema::table('mail_messages', function (Blueprint $t): void {
            $t->foreignId('client_quotation_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('response_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('response_reviewed_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
DROP TRIGGER mail_envelope_lineage ON mail_envelopes;
CREATE OR REPLACE FUNCTION lrs_envelope_lineage_v8() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.followup_stage_id IS NOT NULL THEN
IF NOT EXISTS (SELECT 1 FROM followup_stages s JOIN followup_plans p ON p.id=s.followup_plan_id WHERE s.id=NEW.followup_stage_id AND p.inquiry_id=NEW.inquiry_id AND NEW.source_key='followup:' || s.id::text) THEN RAISE EXCEPTION 'Invalid follow-up envelope lineage'; END IF;
ELSIF NEW.rfq_approval_id IS NOT NULL THEN
IF NOT EXISTS (SELECT 1 FROM rfq_approvals a JOIN rfq_revisions v ON v.id=a.rfq_revision_id JOIN rfqs r ON r.id=v.rfq_id WHERE a.id=NEW.rfq_approval_id AND r.inquiry_id=NEW.inquiry_id AND NEW.source_key='rfq:' || a.id::text) THEN RAISE EXCEPTION 'Invalid RFQ envelope lineage'; END IF;
ELSIF NEW.clarification_id IS NOT NULL THEN
IF NOT EXISTS (SELECT 1 FROM clarifications c WHERE c.id=NEW.clarification_id AND c.inquiry_id=NEW.inquiry_id AND NEW.source_key='clarification:' || c.id::text) THEN RAISE EXCEPTION 'Invalid clarification envelope lineage'; END IF;
ELSE
IF NOT EXISTS (SELECT 1 FROM client_quotation_approvals a JOIN client_quotation_revisions r ON r.id=a.client_quotation_revision_id JOIN client_quotations q ON q.id=r.client_quotation_id WHERE a.id=NEW.client_quotation_approval_id AND q.inquiry_id=NEW.inquiry_id AND NEW.source_key='client_quote:' || a.id::text) THEN RAISE EXCEPTION 'Invalid quotation envelope lineage'; END IF;
END IF; RETURN NEW; END $$;
CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_envelope_lineage_v8();
CREATE TRIGGER followup_policy_immutable BEFORE UPDATE OR DELETE ON followup_policies FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
CREATE TRIGGER followup_authorization_immutable BEFORE UPDATE OR DELETE ON followup_authorizations FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all');
ALTER TABLE followup_authorizations ADD CONSTRAINT one_followup_parent CHECK (num_nonnulls(rfq_approval_id,client_quotation_approval_id)=1);
ALTER TABLE followup_plans ADD CONSTRAINT followup_kind CHECK (kind IN ('rfq','client_quote') AND send_count >= 0);
CREATE OR REPLACE FUNCTION lrs_followup_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Follow-up evidence cannot be deleted'; END IF;
IF TG_TABLE_NAME='followup_plans' THEN
IF NEW.target_key IS DISTINCT FROM OLD.target_key OR NEW.inquiry_id IS DISTINCT FROM OLD.inquiry_id OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.send_count < OLD.send_count THEN RAISE EXCEPTION 'Follow-up identity and cumulative count are protected'; END IF;
IF NEW.authorization_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM followup_authorizations WHERE id=NEW.authorization_id AND followup_plan_id=NEW.id) THEN RAISE EXCEPTION 'Follow-up activation lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME='followup_stages' AND TG_OP='UPDATE' THEN
IF NEW.content IS DISTINCT FROM OLD.content OR NEW.digest IS DISTINCT FROM OLD.digest OR NEW.followup_plan_id IS DISTINCT FROM OLD.followup_plan_id OR NEW.followup_authorization_id IS DISTINCT FROM OLD.followup_authorization_id OR NEW.sequence IS DISTINCT FROM OLD.sequence OR NEW.ordinal IS DISTINCT FROM OLD.ordinal OR NEW.due_at IS DISTINCT FROM OLD.due_at OR (OLD.counted_at IS NOT NULL AND NEW.counted_at IS DISTINCT FROM OLD.counted_at) OR (OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by IS DISTINCT FROM OLD.reviewed_by OR NEW.reviewed_at IS DISTINCT FROM OLD.reviewed_at)) THEN RAISE EXCEPTION 'Frozen reminder evidence cannot change'; END IF;
ELSIF TG_TABLE_NAME='followup_stages' THEN
IF NOT EXISTS (SELECT 1 FROM followup_authorizations a WHERE a.id=NEW.followup_authorization_id AND a.followup_plan_id=NEW.followup_plan_id) THEN RAISE EXCEPTION 'Reminder stage lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME='followup_authorizations' THEN
IF NOT EXISTS (SELECT 1 FROM followup_plans p JOIN followup_policies d ON d.kind=p.kind WHERE p.id=NEW.followup_plan_id AND d.id=NEW.followup_policy_id AND ((NEW.rfq_approval_id IS NOT NULL AND EXISTS(SELECT 1 FROM rfq_approvals a JOIN rfq_revisions r ON r.id=a.rfq_revision_id JOIN rfqs q ON q.id=r.rfq_id WHERE a.id=NEW.rfq_approval_id AND p.target_key='rfq:' || q.id::text AND p.inquiry_id=q.inquiry_id)) OR (NEW.client_quotation_approval_id IS NOT NULL AND EXISTS(SELECT 1 FROM client_quotation_approvals a JOIN client_quotation_revisions r ON r.id=a.client_quotation_revision_id JOIN client_quotations q ON q.id=r.client_quotation_id WHERE a.id=NEW.client_quotation_approval_id AND p.target_key='client_quote:' || q.id::text AND p.inquiry_id=q.inquiry_id)))) THEN RAISE EXCEPTION 'Approval parent lineage mismatch'; END IF;
END IF; RETURN NEW; END $$;
CREATE TRIGGER followup_plan_guard BEFORE UPDATE OR DELETE ON followup_plans FOR EACH ROW EXECUTE FUNCTION lrs_followup_guard();
CREATE TRIGGER followup_stage_guard BEFORE INSERT OR UPDATE OR DELETE ON followup_stages FOR EACH ROW EXECUTE FUNCTION lrs_followup_guard();
CREATE TRIGGER followup_authorization_lineage BEFORE INSERT ON followup_authorizations FOR EACH ROW EXECUTE FUNCTION lrs_followup_guard();
SQL);

    }

    public function down(): void
    {
        if (DB::table('followup_authorizations')->exists()) {
            throw new RuntimeException('Preserve follow-up evidence; use a forward migration instead.');
        }
        DB::statement('DROP TRIGGER mail_envelope_lineage ON mail_envelopes');
        DB::statement('CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_envelope_lineage_v7()');
        DB::statement('DROP FUNCTION lrs_envelope_lineage_v8()');
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK (num_nonnulls(rfq_approval_id,clarification_id,client_quotation_approval_id)=1)');
        Schema::table('mail_messages', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('client_quotation_revision_id');
            $t->dropConstrainedForeignId('response_reviewed_by');
            $t->dropColumn('response_reviewed_at');
        });
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->dropConstrainedForeignId('followup_stage_id'));
        Schema::dropIfExists('attention_tasks');
        Schema::dropIfExists('followup_stages');
        Schema::table('followup_plans', fn (Blueprint $t) => $t->dropForeign(['authorization_id']));
        Schema::dropIfExists('followup_authorizations');
        Schema::dropIfExists('followup_plans');
        Schema::dropIfExists('followup_policies');
        DB::statement('DROP FUNCTION lrs_followup_guard()');
    }
};
