<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotation_manual_sends')) {
            Schema::create('quotation_manual_sends', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('client_quotation_approval_id')->unique()->constrained()->restrictOnDelete();
                $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $t->timestampTz('recorded_at');
                $t->text('reason');
            });
            DB::statement("CREATE TRIGGER quotation_manual_immutable BEFORE UPDATE OR DELETE ON quotation_manual_sends FOR EACH ROW EXECUTE FUNCTION lrs_mail_immutable('all')");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lrs_quotation_parent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
IF TG_OP='DELETE' OR NEW.inquiry_id IS DISTINCT FROM OLD.inquiry_id OR NEW.reference IS DISTINCT FROM OLD.reference OR NEW.current_number < OLD.current_number THEN RAISE EXCEPTION 'Quotation identity and revision sequence are protected'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER quotation_parent_guard BEFORE UPDATE OR DELETE ON client_quotations FOR EACH ROW EXECUTE FUNCTION lrs_quotation_parent_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_manual_sends');
        DB::statement('DROP TRIGGER quotation_parent_guard ON client_quotations');
        DB::statement('DROP FUNCTION lrs_quotation_parent_guard()');
    }
};
