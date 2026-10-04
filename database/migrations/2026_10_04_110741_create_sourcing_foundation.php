<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('rfq_reply_name')->nullable();
            $table->string('rfq_reply_email')->nullable();
            $table->text('rfq_signature')->nullable();
        });
        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('prepared_from_id')->nullable()->index();
            $table->foreign(['prepared_from_id', 'inquiry_id'])->references(['id', 'inquiry_id'])->on('inquiry_documents')->restrictOnDelete();
        });
        Schema::table('shipment_versions', function (Blueprint $table): void {
            $table->unique(['id', 'inquiry_id']);
        });
        Schema::create('sourcing_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('shipment_version_id')->unique();
            $table->foreign(['shipment_version_id', 'inquiry_id'])->references(['id', 'inquiry_id'])->on('shipment_versions')->restrictOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'inquiry_id']);
        });
        Schema::create('rfqs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sourcing_round_id');
            $table->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $table->foreign(['sourcing_round_id', 'inquiry_id'])->references(['id', 'inquiry_id'])->on('sourcing_rounds')->restrictOnDelete();
            $table->foreignId('vendor_id')->index()->constrained()->restrictOnDelete();
            $table->string('reference')->unique();
            $table->unsignedInteger('current_number')->default(1);
            $table->timestampsTz();
            $table->unique(['sourcing_round_id', 'vendor_id']);
        });
        Schema::create('rfq_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->default('draft');
            $table->jsonb('payload');
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('change_reason', 2000)->nullable();
            $table->timestampsTz();
            $table->unique(['rfq_id', 'number']);
        });
        DB::statement("ALTER TABLE rfq_revisions ADD CONSTRAINT rfq_revision_state CHECK (status IN ('draft','needs_approval','changes_requested','approved','superseded','cancelled'))");
        Schema::create('rfq_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_revision_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->string('digest', 64);
            $table->foreignId('approved_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('approver_name');
            $table->timestampTz('approved_at');
        });
        Schema::create('rfq_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_approval_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('action_key')->unique();
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->timestampTz('sent_at');
            $table->timestampTz('recorded_at');
            $table->string('channel');
            $table->jsonb('recipients');
            $table->text('evidence')->nullable();
        });
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('purpose')->default('shipment_proposals');
            $table->foreignId('rfq_revision_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_rfq_evidence() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'RFQ evidence cannot be deleted'; END IF;
 IF TG_TABLE_NAME IN ('rfq_approvals','rfq_dispatches','sourcing_rounds') THEN RAISE EXCEPTION 'RFQ snapshots are immutable'; END IF;
 IF TG_TABLE_NAME = 'rfq_revisions' THEN
 IF (NEW.rfq_id,NEW.number,NEW.payload,NEW.created_by,NEW.change_reason) IS DISTINCT FROM (OLD.rfq_id,OLD.number,OLD.payload,OLD.created_by,OLD.change_reason) THEN RAISE EXCEPTION 'Saved RFQ revisions are immutable'; END IF;
 END IF;
 IF TG_TABLE_NAME = 'ai_runs' THEN
 IF (NEW.purpose,NEW.rfq_revision_id) IS DISTINCT FROM (OLD.purpose,OLD.rfq_revision_id) THEN RAISE EXCEPTION 'AI purpose binding is immutable'; END IF;
 END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER rfq_round_evidence BEFORE UPDATE OR DELETE ON sourcing_rounds FOR EACH ROW EXECUTE FUNCTION protect_rfq_evidence();
CREATE TRIGGER rfq_revision_evidence BEFORE UPDATE OR DELETE ON rfq_revisions FOR EACH ROW EXECUTE FUNCTION protect_rfq_evidence();
CREATE TRIGGER rfq_approval_evidence BEFORE UPDATE OR DELETE ON rfq_approvals FOR EACH ROW EXECUTE FUNCTION protect_rfq_evidence();
CREATE TRIGGER rfq_dispatch_evidence BEFORE UPDATE OR DELETE ON rfq_dispatches FOR EACH ROW EXECUTE FUNCTION protect_rfq_evidence();
CREATE TRIGGER ai_purpose_evidence BEFORE UPDATE OR DELETE ON ai_runs FOR EACH ROW EXECUTE FUNCTION protect_rfq_evidence();
SQL);
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rfq_revision_id');
            $table->dropColumn('purpose');
        });
        DB::statement('DROP TRIGGER IF EXISTS ai_purpose_evidence ON ai_runs');
        foreach (['rfq_dispatches', 'rfq_approvals', 'rfq_revisions', 'rfqs', 'sourcing_rounds'] as $name) {
            Schema::dropIfExists($name);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_rfq_evidence()');
        Schema::table('shipment_versions', function (Blueprint $table): void {
            $table->dropUnique(['id', 'inquiry_id']);
        });
        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->dropForeign(['prepared_from_id', 'inquiry_id']);
            $table->dropColumn(['prepared_from_id', 'version']);
        });
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['rfq_reply_name', 'rfq_reply_email', 'rfq_signature']);
        });
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
