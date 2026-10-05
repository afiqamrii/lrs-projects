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
            $t->boolean('outbound_paused')->default(false);
            $t->unsignedInteger('outbound_epoch')->default(0);
            $t->text('outbound_reason')->nullable();
            $t->foreignId('outbound_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('outbound_changed_at')->nullable();
            $t->timestampTz('scheduler_seen_at')->nullable();
            $t->timestampTz('worker_seen_at')->nullable();
        });
        Schema::table('mail_dispatches', fn (Blueprint $t) => $t->unsignedInteger('outbound_epoch')->default(0));
        Schema::table('followup_plans', fn (Blueprint $t) => $t->unsignedInteger('outbound_epoch')->default(0));
        Schema::table('inquiries', fn (Blueprint $t) => $t->index(['is_demo', 'received_at', 'id'], 'inquiries_report_cohort'));
        Schema::table('mail_messages', fn (Blueprint $t) => $t->index(['rfq_revision_id', 'direction', 'received_at'], 'mail_rfq_response_time'));
        Schema::table('ai_runs', fn (Blueprint $t) => $t->index(['created_at', 'inquiry_id'], 'ai_reporting_period'));
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT outbound_epoch_positive CHECK (outbound_epoch >= 0)');
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Preserve operational pause history. Use a forward migration on populated installations.');
        }
        Schema::table('ai_runs', fn (Blueprint $t) => $t->dropIndex('ai_reporting_period'));
        Schema::table('mail_messages', fn (Blueprint $t) => $t->dropIndex('mail_rfq_response_time'));
        Schema::table('inquiries', fn (Blueprint $t) => $t->dropIndex('inquiries_report_cohort'));
        Schema::table('mail_dispatches', fn (Blueprint $t) => $t->dropColumn('outbound_epoch'));
        Schema::table('followup_plans', fn (Blueprint $t) => $t->dropColumn('outbound_epoch'));
        DB::statement('ALTER TABLE company_settings DROP CONSTRAINT outbound_epoch_positive');
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('outbound_changed_by');
            $t->dropColumn(['outbound_paused', 'outbound_epoch', 'outbound_reason', 'outbound_changed_at', 'scheduler_seen_at', 'worker_seen_at']);
        });
    }
};
