<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->string('workspace_data_mode')->default('real');
        });
        Schema::table('inquiries', function (Blueprint $t): void {
            $t->string('sample_set')->nullable()->index();
        });
        foreach (['vendors', 'clients'] as $name) {
            Schema::table($name, function (Blueprint $t): void {
                $t->boolean('is_demo')->default(false);
            });
        }
        Schema::create('vendor_offers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('rfq_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('shipment_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('sourcing_round_id')->constrained()->restrictOnDelete();
            $t->string('alternative');
            $t->string('source_identity', 64);
            $t->jsonb('source');
            $t->unsignedInteger('current_number')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['inquiry_id', 'source_identity', 'alternative']);
        });
        Schema::create('vendor_offer_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vendor_offer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('status');
            $t->jsonb('payload');
            $t->jsonb('calculation');
            $t->jsonb('gaps');
            $t->decimal('known_total', 30, 8)->nullable();
            $t->decimal('complete_total', 30, 8)->nullable();
            $t->decimal('quoted_total', 30, 8)->nullable();
            $t->string('currency', 3);
            $t->string('digest', 64);
            $t->text('change_reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['vendor_offer_id', 'number']);
        });
        Schema::create('vendor_offer_charges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('vendor_offer_revision_id')->constrained()->restrictOnDelete();
            $t->string('line_key');
            $t->jsonb('evidence');
            $t->string('state');
            $t->string('basis');
            $t->string('currency', 3);
            foreach (['rate', 'quantity', 'minimum_charge', 'minimum_quantity', 'amount'] as $n) {
                $t->decimal($n, 30, 8)->nullable();
            }$t->unique(['vendor_offer_revision_id', 'line_key']);
        });
        Schema::create('offer_comparisons', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('shipment_version_id')->constrained()->restrictOnDelete();
            $t->string('currency', 3);
            $t->jsonb('fx');
            $t->string('digest', 64);
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
        });
        Schema::create('offer_selections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('inquiry_id')->index()->constrained()->restrictOnDelete();
            $t->foreignId('vendor_offer_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('offer_comparison_id')->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->jsonb('snapshot');
            $t->string('digest', 64);
            $t->text('reason');
            $t->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('selected_at');
            $t->timestampTz('superseded_at')->nullable();
        });
        Schema::table('ai_runs', function (Blueprint $t): void {
            $t->foreignId('vendor_offer_revision_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE vendor_offer_revisions ADD CONSTRAINT offer_state CHECK (status IN ('draft','needs_review','reviewed_gaps','reviewed_complete','superseded'))");
        DB::statement("ALTER TABLE offer_selections ADD CONSTRAINT selection_kind CHECK (kind IN ('final','provisional'))");
        DB::statement('CREATE UNIQUE INDEX one_current_offer_selection ON offer_selections(inquiry_id) WHERE superseded_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_offer_evidence() RETURNS trigger AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Commercial evidence cannot be deleted'; END IF;
 IF TG_TABLE_NAME IN ('vendor_offer_charges','offer_comparisons') THEN RAISE EXCEPTION 'Commercial evidence is immutable'; END IF;
 IF TG_TABLE_NAME='vendor_offers' AND (to_jsonb(NEW)-'current_number'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'current_number'-'updated_at') THEN RAISE EXCEPTION 'Offer source binding is immutable'; END IF;
 IF TG_TABLE_NAME='vendor_offer_revisions' AND (to_jsonb(NEW)-'status'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'updated_at') THEN RAISE EXCEPTION 'Saved commercial revisions are immutable'; END IF;
 IF TG_TABLE_NAME='offer_selections' AND ((to_jsonb(NEW)-'superseded_at') IS DISTINCT FROM (to_jsonb(OLD)-'superseded_at') OR (to_jsonb(OLD)->>'superseded_at') IS NOT NULL) THEN RAISE EXCEPTION 'Selected cost basis is immutable'; END IF;
 IF TG_TABLE_NAME='ai_runs' AND (to_jsonb(NEW)->>'vendor_offer_revision_id') IS DISTINCT FROM (to_jsonb(OLD)->>'vendor_offer_revision_id') THEN RAISE EXCEPTION 'Quotation AI binding is immutable'; END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach (['vendor_offers', 'vendor_offer_revisions', 'vendor_offer_charges', 'offer_comparisons', 'offer_selections', 'ai_runs'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_commercial_evidence BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION protect_offer_evidence()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS ai_runs_commercial_evidence ON ai_runs');
        Schema::table('ai_runs', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('vendor_offer_revision_id');
        });
        foreach (['offer_selections', 'offer_comparisons', 'vendor_offer_charges', 'vendor_offer_revisions', 'vendor_offers'] as $n) {
            Schema::dropIfExists($n);
        }
        DB::statement('DROP FUNCTION IF EXISTS protect_offer_evidence()');
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->dropColumn('workspace_data_mode');
        });
        Schema::table('inquiries', function (Blueprint $t): void {
            $t->dropColumn('sample_set');
        });
        foreach (['vendors', 'clients'] as $n) {
            Schema::table($n, function (Blueprint $t): void {
                $t->dropColumn('is_demo');
            });
        }
    }
};
