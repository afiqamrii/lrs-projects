<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->unique(['id', 'inquiry_id']);
        });
        Schema::create('document_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->foreign(['document_id', 'inquiry_id'])->references(['id', 'inquiry_id'])->on('inquiry_documents')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('identity', 64);
            $table->unsignedInteger('generation');
            $table->string('checksum', 64);
            $table->jsonb('configuration');
            $table->jsonb('selection');
            $table->string('state')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('retry_reason', 1000)->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('blocks')->default('[]');
            $table->jsonb('pages')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->unsignedInteger('total_pages')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['inquiry_id', 'identity', 'generation']);
            $table->index(['inquiry_id', 'state']);
        });
        DB::statement("CREATE UNIQUE INDEX document_runs_active ON document_runs (inquiry_id, identity) WHERE state IN ('queued','processing')");
        DB::statement("ALTER TABLE document_runs ADD CONSTRAINT document_run_state CHECK (state IN ('queued','processing','extracted','partial','manual_review','failed','unavailable'))");

        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('model')->nullable();
            $table->jsonb('configuration')->default('{}');
            $table->jsonb('model_check')->nullable();
            $table->timestampsTz();
        });
        DB::table('ai_settings')->insert(['id' => 1, 'enabled' => false, 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('ALTER TABLE ai_settings ADD CONSTRAINT singleton_ai_settings CHECK (id = 1)');

        Schema::create('ai_budget_days', function (Blueprint $table): void {
            $table->id();
            $table->date('day')->unique();
            $table->decimal('reserved', 18, 8)->default(0);
            $table->decimal('spent', 18, 8)->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE ai_budget_days ADD CONSTRAINT positive_ai_budget CHECK (reserved >= 0 AND spent >= 0)');

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('budget_day_id')->nullable()->constrained('ai_budget_days')->restrictOnDelete();
            $table->string('identity', 64);
            $table->unsignedInteger('generation');
            $table->string('shipment_hash', 64);
            $table->unsignedInteger('shipment_revision');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('schema_version');
            $table->jsonb('settings');
            $table->jsonb('sources');
            $table->jsonb('working_snapshot');
            $table->unsignedInteger('input_characters');
            $table->unsignedInteger('input_bound');
            $table->string('state')->default('queued');
            $table->string('review_outcome')->default('unreviewed');
            $table->boolean('is_demo')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('retry_reason', 1000)->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('provider_response_id')->nullable();
            $table->string('provider_model')->nullable();
            $table->string('provider_status')->nullable();
            $table->jsonb('usage')->nullable();
            $table->decimal('reservation', 18, 8)->default(0);
            $table->decimal('estimated_cost', 18, 8)->nullable();
            $table->boolean('cost_uncertain')->default(false);
            $table->jsonb('result')->nullable();
            $table->jsonb('proposals')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['inquiry_id', 'identity', 'generation']);
            $table->index(['inquiry_id', 'state']);
        });
        DB::statement("CREATE UNIQUE INDEX ai_runs_active ON ai_runs (inquiry_id, identity) WHERE state IN ('queued','processing')");
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_run_state CHECK (state IN ('queued','processing','needs_review','failed','unavailable'))");
        DB::statement('ALTER TABLE ai_runs ADD CONSTRAINT positive_ai_cost CHECK (reservation >= 0 AND (estimated_cost IS NULL OR estimated_cost >= 0))');

        Schema::create('proposal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->uuid('action_key')->unique();
            $table->string('decision_hash', 64);
            $table->jsonb('decisions');
            $table->jsonb('before_values');
            $table->jsonb('after_values');
            $table->unsignedInteger('resulting_revision');
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_processing_evidence() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Processing evidence cannot be deleted'; END IF;
 IF TG_TABLE_NAME = 'proposal_reviews' THEN RAISE EXCEPTION 'Review records are immutable'; END IF;
 IF TG_TABLE_NAME = 'document_runs' THEN
  IF (NEW.inquiry_id,NEW.document_id,NEW.checksum,NEW.identity,NEW.configuration,NEW.selection) IS DISTINCT FROM (OLD.inquiry_id,OLD.document_id,OLD.checksum,OLD.identity,OLD.configuration,OLD.selection)
   OR (OLD.state IN ('extracted','partial','manual_review') AND (NEW.blocks,NEW.pages,NEW.warnings) IS DISTINCT FROM (OLD.blocks,OLD.pages,OLD.warnings)) THEN RAISE EXCEPTION 'Extraction evidence is immutable'; END IF;
 ELSE
  IF (NEW.inquiry_id,NEW.identity,NEW.sources,NEW.working_snapshot,NEW.shipment_hash,NEW.shipment_revision,NEW.model,NEW.prompt_version,NEW.schema_version,NEW.settings)
   IS DISTINCT FROM (OLD.inquiry_id,OLD.identity,OLD.sources,OLD.working_snapshot,OLD.shipment_hash,OLD.shipment_revision,OLD.model,OLD.prompt_version,OLD.schema_version,OLD.settings)
   OR (OLD.result IS NOT NULL AND (NEW.result,NEW.proposals) IS DISTINCT FROM (OLD.result,OLD.proposals)) THEN RAISE EXCEPTION 'AI source and result evidence is immutable'; END IF;
 END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER document_runs_evidence BEFORE UPDATE OR DELETE ON document_runs FOR EACH ROW EXECUTE FUNCTION protect_processing_evidence();
CREATE TRIGGER ai_runs_evidence BEFORE UPDATE OR DELETE ON ai_runs FOR EACH ROW EXECUTE FUNCTION protect_processing_evidence();
CREATE TRIGGER proposal_reviews_evidence BEFORE UPDATE OR DELETE ON proposal_reviews FOR EACH ROW EXECUTE FUNCTION protect_processing_evidence();
SQL);
    }

    public function down(): void
    {
        foreach (['proposal_reviews', 'ai_runs', 'ai_budget_days', 'ai_settings', 'document_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_processing_evidence()');
        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->dropUnique(['id', 'inquiry_id']);
        });
    }
};
