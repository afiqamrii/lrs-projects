<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('client_quotation_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->uuid('action_key')->unique();
            $t->char('request_digest', 64);
            $t->foreignId('corrects_id')->nullable()->unique()->constrained('client_decisions')->restrictOnDelete();
            $t->foreignId('mail_message_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('client_contact_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->string('requested_outcome', 24);
            $t->string('outcome', 24);
            $t->string('channel', 24);
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->timestampTz('decided_at');
            $t->foreignId('reviewed_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['client_quotation_revision_id', 'number']);
        });
        Schema::create('vendor_reconfirmations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('client_decision_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('offer_selection_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('shipment_version_id')->index()->constrained()->restrictOnDelete();
            $t->unsignedInteger('current_number')->default(0);
            $t->timestampsTz();
        });
        Schema::create('vendor_confirmations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_reconfirmation_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->uuid('action_key')->unique();
            $t->char('request_digest', 64);
            $t->foreignId('mail_message_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('contact_id')->nullable()->index()->constrained('contacts')->restrictOnDelete();
            $t->string('status', 24);
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->timestampTz('confirmed_at');
            $t->timestampTz('expires_at')->nullable();
            $t->foreignId('reviewed_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['vendor_reconfirmation_id', 'number']);
        });
        Schema::create('handoff_policies', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('number')->unique();
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->foreignId('approved_by')->index()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestampTz('created_at');
        });
        Schema::create('booking_handoffs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('current_number')->default(0);
            $t->timestampsTz();
        });
        Schema::create('handoff_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('booking_handoff_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->foreignId('client_decision_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('client_quotation_revision_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('offer_selection_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('shipment_version_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_confirmation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('handoff_policy_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->string('state', 24);
            $t->text('snapshot');
            $t->char('dependency_digest', 64);
            $t->char('digest', 64);
            $t->string('pdf_path');
            $t->char('pdf_checksum', 64);
            $t->unsignedBigInteger('pdf_size');
            $t->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['booking_handoff_id', 'number']);
        });
        Schema::create('handoff_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('handoff_revision_id')->unique()->constrained()->restrictOnDelete();
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->string('pdf_path');
            $t->char('pdf_checksum', 64);
            $t->unsignedBigInteger('pdf_size');
            $t->foreignId('approved_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at');
        });
        Schema::create('operational_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_reconfirmation_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('handoff_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('kind', 24);
            $t->text('content');
            $t->char('dependency_digest', 64);
            $t->char('digest', 64);
            $t->foreignId('resend_of_id')->nullable()->index()->constrained('mail_dispatches')->restrictOnDelete();
            $t->text('reason');
            $t->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['vendor_reconfirmation_id', 'number']);
            $t->unique(['handoff_revision_id', 'number']);
        });
        Schema::create('operational_message_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('operational_message_id')->unique()->constrained()->restrictOnDelete();
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->foreignId('approved_by')->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at');
        });
        Schema::create('handoff_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('handoff_approval_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('corrects_id')->nullable()->unique()->constrained('handoff_events')->restrictOnDelete();
            $t->uuid('action_key')->unique();
            $t->char('request_digest', 64);
            $t->string('kind', 32);
            $t->text('snapshot');
            $t->char('digest', 64);
            $t->timestampTz('occurred_at');
            $t->foreignId('recorded_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
        });
        Schema::table('inquiries', fn (Blueprint $t) => $t->unsignedInteger('lifecycle_generation')->default(0));
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->foreignId('operational_message_approval_id')->nullable()->index()->constrained()->restrictOnDelete());
        Schema::table('mail_messages', fn (Blueprint $t) => $t->foreignId('operational_message_id')->nullable()->index()->constrained()->restrictOnDelete());
        DB::statement('ALTER TABLE attention_tasks ALTER COLUMN followup_plan_id DROP NOT NULL');
        Schema::table('attention_tasks', function (Blueprint $t): void {
            $t->foreignId('client_quotation_revision_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_reconfirmation_id')->nullable()->index()->constrained()->restrictOnDelete();
            $t->foreignId('handoff_revision_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::statement('ALTER TABLE attention_tasks ADD CONSTRAINT attention_one_target CHECK (num_nonnulls(followup_plan_id,client_quotation_revision_id,vendor_reconfirmation_id,handoff_revision_id)=1)');
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK (num_nonnulls(rfq_approval_id,clarification_id,client_quotation_approval_id,followup_stage_id,operational_message_approval_id)=1)');
        DB::statement("ALTER TABLE client_decisions ADD CONSTRAINT client_decision_outcome CHECK (outcome IN ('accepted','revision_requested','declined','question','review_required'))");
        DB::statement("ALTER TABLE vendor_confirmations ADD CONSTRAINT vendor_confirmation_state CHECK (status IN ('pending','confirmed','conditional','changed','unavailable'))");
        DB::statement("ALTER TABLE operational_messages ADD CONSTRAINT operational_parent CHECK ((kind='reconfirmation' AND vendor_reconfirmation_id IS NOT NULL AND handoff_revision_id IS NULL) OR (kind='booking' AND handoff_revision_id IS NOT NULL AND vendor_reconfirmation_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX handoff_once_to_operations ON handoff_events(handoff_approval_id) WHERE kind='handed_to_operations'");
        DB::statement("CREATE UNIQUE INDEX booking_one_original_evidence ON handoff_events(handoff_approval_id) WHERE kind='booking_confirmed' AND corrects_id IS NULL");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_lifecycle_epoch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.shipment IS DISTINCT FROM OLD.shipment OR NEW.client_id IS DISTINCT FROM OLD.client_id OR NEW.client_contact_id IS DISTINCT FROM OLD.client_contact_id OR NEW.shipment_revision IS DISTINCT FROM OLD.shipment_revision OR (NEW.status IS DISTINCT FROM OLD.status AND (NEW.status IN ('on_hold','closed') OR OLD.status IN ('on_hold','closed'))) THEN NEW.lifecycle_generation=OLD.lifecycle_generation+1;
ELSIF NEW.lifecycle_generation IS DISTINCT FROM OLD.lifecycle_generation THEN RAISE EXCEPTION 'Lifecycle generation changes only with material inquiry transitions'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER inquiry_lifecycle_epoch BEFORE UPDATE ON inquiries FOR EACH ROW EXECUTE FUNCTION lrs_lifecycle_epoch();
CREATE OR REPLACE FUNCTION lrs_lifecycle_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_TABLE_NAME='client_decisions' THEN
IF NOT EXISTS(SELECT 1 FROM client_quotation_revisions r JOIN client_quotations q ON q.id=r.client_quotation_id WHERE r.id=NEW.client_quotation_revision_id AND q.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Client decision quotation lineage mismatch'; END IF;
IF NEW.corrects_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM client_decisions d WHERE d.id=NEW.corrects_id AND d.client_quotation_revision_id=NEW.client_quotation_revision_id AND d.inquiry_id=NEW.inquiry_id AND d.number+1=NEW.number) THEN RAISE EXCEPTION 'Client decision correction lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME='vendor_reconfirmations' THEN
IF NOT EXISTS(SELECT 1 FROM client_decisions d JOIN client_quotation_revisions r ON r.id=d.client_quotation_revision_id JOIN offer_selections s ON s.id=r.offer_selection_id JOIN vendor_offer_revisions v ON v.id=s.vendor_offer_revision_id JOIN vendor_offers o ON o.id=v.vendor_offer_id WHERE d.id=NEW.client_decision_id AND d.inquiry_id=NEW.inquiry_id AND d.outcome='accepted' AND s.id=NEW.offer_selection_id AND o.shipment_version_id=NEW.shipment_version_id) THEN RAISE EXCEPTION 'Vendor reconfirmation requires exact accepted selection'; END IF;
ELSIF TG_TABLE_NAME='vendor_confirmations' THEN
IF NOT EXISTS(SELECT 1 FROM vendor_reconfirmations r WHERE r.id=NEW.vendor_reconfirmation_id AND r.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Vendor confirmation lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME='operational_messages' THEN
IF NEW.kind='reconfirmation' THEN
IF NOT EXISTS(SELECT 1 FROM vendor_reconfirmations r WHERE r.id=NEW.vendor_reconfirmation_id AND r.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Reconfirmation mail lineage mismatch'; END IF;
ELSE
IF NOT EXISTS(SELECT 1 FROM handoff_revisions r JOIN booking_handoffs h ON h.id=r.booking_handoff_id WHERE r.id=NEW.handoff_revision_id AND h.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Booking mail handoff lineage mismatch'; END IF;
END IF;
ELSIF TG_TABLE_NAME='handoff_events' AND NEW.handoff_approval_id IS NOT NULL THEN
IF NOT EXISTS(SELECT 1 FROM handoff_approvals a JOIN handoff_revisions r ON r.id=a.handoff_revision_id JOIN booking_handoffs h ON h.id=r.booking_handoff_id WHERE a.id=NEW.handoff_approval_id AND h.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Handoff event lineage mismatch'; END IF;
END IF; RETURN NEW; END $$;
CREATE OR REPLACE FUNCTION lrs_lifecycle_pointer() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Lifecycle history cannot be deleted'; END IF;
IF NEW.inquiry_id IS DISTINCT FROM OLD.inquiry_id OR NEW.current_number < OLD.current_number THEN RAISE EXCEPTION 'Lifecycle identity and version pointer are protected'; END IF;
IF TG_TABLE_NAME='vendor_reconfirmations' THEN
IF NEW.client_decision_id IS DISTINCT FROM OLD.client_decision_id OR NEW.offer_selection_id IS DISTINCT FROM OLD.offer_selection_id OR NEW.shipment_version_id IS DISTINCT FROM OLD.shipment_version_id THEN RAISE EXCEPTION 'Reconfirmation source is immutable'; END IF;
END IF;
RETURN NEW; END $$;
CREATE OR REPLACE FUNCTION lrs_envelope_lineage_v10() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF NEW.operational_message_approval_id IS NOT NULL THEN
IF NOT EXISTS(SELECT 1 FROM operational_message_approvals a JOIN operational_messages m ON m.id=a.operational_message_id WHERE a.id=NEW.operational_message_approval_id AND m.inquiry_id=NEW.inquiry_id AND NEW.source_key='operations:' || a.id::text) THEN RAISE EXCEPTION 'Operational envelope lineage mismatch'; END IF;
ELSIF NEW.followup_stage_id IS NOT NULL THEN
IF NOT EXISTS(SELECT 1 FROM followup_stages s JOIN followup_plans p ON p.id=s.followup_plan_id WHERE s.id=NEW.followup_stage_id AND p.inquiry_id=NEW.inquiry_id AND NEW.source_key='followup:' || s.id::text) THEN RAISE EXCEPTION 'Invalid follow-up envelope lineage'; END IF;
ELSIF NEW.rfq_approval_id IS NOT NULL THEN
IF NOT EXISTS(SELECT 1 FROM rfq_approvals a JOIN rfq_revisions v ON v.id=a.rfq_revision_id JOIN rfqs r ON r.id=v.rfq_id WHERE a.id=NEW.rfq_approval_id AND r.inquiry_id=NEW.inquiry_id AND NEW.source_key='rfq:' || a.id::text) THEN RAISE EXCEPTION 'Invalid RFQ envelope lineage'; END IF;
ELSIF NEW.clarification_id IS NOT NULL THEN
IF NOT EXISTS(SELECT 1 FROM clarifications c WHERE c.id=NEW.clarification_id AND c.inquiry_id=NEW.inquiry_id AND NEW.source_key='clarification:' || c.id::text) THEN RAISE EXCEPTION 'Invalid clarification envelope lineage'; END IF;
ELSE
IF NOT EXISTS(SELECT 1 FROM client_quotation_approvals a JOIN client_quotation_revisions r ON r.id=a.client_quotation_revision_id JOIN client_quotations q ON q.id=r.client_quotation_id WHERE a.id=NEW.client_quotation_approval_id AND q.inquiry_id=NEW.inquiry_id AND NEW.source_key='client_quote:' || a.id::text) THEN RAISE EXCEPTION 'Invalid quotation envelope lineage'; END IF;
END IF; RETURN NEW; END $$;
DROP TRIGGER mail_envelope_lineage ON mail_envelopes;
CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_envelope_lineage_v10();
SQL);
        foreach (['client_decisions', 'vendor_confirmations', 'handoff_policies', 'handoff_revisions', 'handoff_approvals', 'operational_messages', 'operational_message_approvals', 'handoff_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all')");
        }
        foreach (['client_decisions', 'vendor_reconfirmations', 'vendor_confirmations', 'operational_messages', 'handoff_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_lineage BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION lrs_lifecycle_lineage()");
        }
        foreach (['vendor_reconfirmations', 'booking_handoffs'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_pointer BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION lrs_lifecycle_pointer()");
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Preserve outcome, booking and mail evidence. Use a forward migration on populated applications.');
        }
        DB::statement('DROP TRIGGER mail_envelope_lineage ON mail_envelopes');
        DB::statement('CREATE TRIGGER mail_envelope_lineage BEFORE INSERT ON mail_envelopes FOR EACH ROW EXECUTE FUNCTION lrs_envelope_lineage_v8()');
        DB::statement('DROP FUNCTION lrs_envelope_lineage_v10()');
        DB::statement('ALTER TABLE mail_envelopes DROP CONSTRAINT one_approved_source');
        DB::statement('ALTER TABLE mail_envelopes ADD CONSTRAINT one_approved_source CHECK (num_nonnulls(rfq_approval_id,clarification_id,client_quotation_approval_id,followup_stage_id)=1)');
        Schema::table('mail_envelopes', fn (Blueprint $t) => $t->dropConstrainedForeignId('operational_message_approval_id'));
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('operational_message_id'));
        DB::statement('ALTER TABLE attention_tasks DROP CONSTRAINT attention_one_target');
        Schema::table('attention_tasks', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('client_quotation_revision_id');
            $t->dropConstrainedForeignId('vendor_reconfirmation_id');
            $t->dropConstrainedForeignId('handoff_revision_id');
        });
        if (! DB::table('attention_tasks')->whereNull('followup_plan_id')->exists()) {
            DB::statement('ALTER TABLE attention_tasks ALTER COLUMN followup_plan_id SET NOT NULL');
        }
        DB::statement('DROP TRIGGER inquiry_lifecycle_epoch ON inquiries');
        Schema::table('inquiries', fn (Blueprint $t) => $t->dropColumn('lifecycle_generation'));
        foreach (['handoff_events', 'operational_message_approvals', 'operational_messages', 'handoff_approvals', 'handoff_revisions', 'booking_handoffs', 'handoff_policies', 'vendor_confirmations', 'vendor_reconfirmations', 'client_decisions'] as $table) {
            Schema::drop($table);
        }
        DB::statement('DROP FUNCTION lrs_lifecycle_epoch()');
        DB::statement('DROP FUNCTION lrs_lifecycle_lineage()');
        DB::statement('DROP FUNCTION lrs_lifecycle_pointer()');
    }
};
