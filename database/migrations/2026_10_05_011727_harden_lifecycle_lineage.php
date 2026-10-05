<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_handoff_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_TABLE_NAME='handoff_revisions' THEN
 IF NEW.client_quotation_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM client_quotation_revisions q JOIN client_quotations a ON a.id=q.client_quotation_id JOIN booking_handoffs h ON h.inquiry_id=a.inquiry_id WHERE h.id=NEW.booking_handoff_id AND q.id=NEW.client_quotation_revision_id AND (NEW.offer_selection_id IS NULL OR q.offer_selection_id=NEW.offer_selection_id)) THEN RAISE EXCEPTION 'Handoff quotation/cost lineage mismatch'; END IF;
 IF NEW.client_decision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM client_decisions d WHERE d.id=NEW.client_decision_id AND d.client_quotation_revision_id=NEW.client_quotation_revision_id) THEN RAISE EXCEPTION 'Handoff exact decision mismatch'; END IF;
 IF NEW.vendor_confirmation_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM vendor_confirmations c JOIN vendor_reconfirmations r ON r.id=c.vendor_reconfirmation_id WHERE c.id=NEW.vendor_confirmation_id AND r.client_decision_id=NEW.client_decision_id AND r.offer_selection_id=NEW.offer_selection_id AND r.shipment_version_id=NEW.shipment_version_id) THEN RAISE EXCEPTION 'Handoff vendor reconfirmation mismatch'; END IF;
ELSIF TG_TABLE_NAME='handoff_approvals' THEN
 IF NOT EXISTS(SELECT 1 FROM handoff_revisions r JOIN booking_handoffs h ON h.id=r.booking_handoff_id WHERE r.id=NEW.handoff_revision_id AND h.current_number=r.number AND r.state='ready') THEN RAISE EXCEPTION 'Approval needs current ready handoff'; END IF;
ELSIF TG_TABLE_NAME='handoff_events' THEN
 IF NEW.corrects_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM handoff_events e WHERE e.id=NEW.corrects_id AND e.inquiry_id=NEW.inquiry_id AND e.handoff_approval_id=NEW.handoff_approval_id AND e.kind=NEW.kind) THEN RAISE EXCEPTION 'Booking correction lineage mismatch'; END IF;
ELSIF TG_TABLE_NAME='attention_tasks' THEN
 IF NEW.client_quotation_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM client_quotation_revisions r JOIN client_quotations q ON q.id=r.client_quotation_id WHERE r.id=NEW.client_quotation_revision_id AND q.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Decision task lineage mismatch'; END IF;
 IF NEW.vendor_reconfirmation_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM vendor_reconfirmations r WHERE r.id=NEW.vendor_reconfirmation_id AND r.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Vendor task lineage mismatch'; END IF;
 IF NEW.handoff_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM handoff_revisions r JOIN booking_handoffs h ON h.id=r.booking_handoff_id WHERE r.id=NEW.handoff_revision_id AND h.inquiry_id=NEW.inquiry_id) THEN RAISE EXCEPTION 'Handoff task lineage mismatch'; END IF;
END IF;
RETURN NEW;
END $$;
SQL);
        foreach (['handoff_revisions', 'handoff_approvals', 'handoff_events', 'attention_tasks'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_exact_lineage BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION lrs_handoff_lineage()");
        }
        DB::statement("ALTER TABLE handoff_events ADD CONSTRAINT actual_booking_has_handoff CHECK (kind NOT IN ('handed_to_operations','booking_requested','booking_confirmed') OR handoff_approval_id IS NOT NULL)");
    }

    public function down(): void
    {
        if (! app()->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
            throw new RuntimeException('Lifecycle history uses forward migrations outside isolated tests.');
        }
        foreach (['handoff_revisions', 'handoff_approvals', 'handoff_events', 'attention_tasks'] as $table) {
            DB::statement("DROP TRIGGER {$table}_exact_lineage ON {$table}");
        }
        DB::statement('ALTER TABLE handoff_events DROP CONSTRAINT actual_booking_has_handoff');
        DB::statement('DROP FUNCTION lrs_handoff_lineage()');
    }
};
