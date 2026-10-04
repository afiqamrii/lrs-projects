<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reserved .test addresses identify local acceptance fixtures, including older
     * client-directory inquiries. Preserve their source, approvals and history.
     */
    public function up(): void
    {
        DB::statement("UPDATE inquiries SET is_demo = true WHERE public_contact->>'email' ILIKE '%.test' OR client_contact_id IN (SELECT id FROM client_contacts WHERE email ILIKE '%.test')");
    }

    /**
     * Keep truthful fixture labels on rollback; the foundation rollback removes
     * the provenance column when the entire mail foundation is removed.
     */
    public function down(): void {}
};
