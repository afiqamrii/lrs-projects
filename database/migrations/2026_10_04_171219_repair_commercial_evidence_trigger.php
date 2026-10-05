<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void {}
};
