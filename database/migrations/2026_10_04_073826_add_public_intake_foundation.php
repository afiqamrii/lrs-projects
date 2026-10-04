<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inquiries ALTER COLUMN client_id DROP NOT NULL, ALTER COLUMN owner_id DROP NOT NULL');
        DB::statement('ALTER TABLE inquiry_documents ALTER COLUMN uploader_id DROP NOT NULL');
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->boolean('public_intake_enabled')->default(true);
            $table->text('public_service_intro')->default('Tell us about your general cargo sea shipment. Share what you know; our team will assess the request before preparing a quotation.');
            $table->string('public_contact_email')->nullable();
            $table->string('public_contact_phone', 64)->nullable();
            $table->text('public_contact_address')->nullable();
            $table->text('public_privacy_notice')->default('We use the information and documents you provide to assess and respond to your shipment request. Access is limited to authorized staff. Please share only information relevant to this request. Contact the company for questions about your information.');
            $table->string('public_privacy_version', 64)->default('2026-10-04.1');
            $table->foreignId('public_intake_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('receipt_mail_enabled')->default(false);
            $table->text('receipt_mail_body')->default('We received your shipment request. Our team will review it and contact you if more information is needed. This receipt is not a quotation, price confirmation or booking.');
        });
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->jsonb('public_contact')->nullable();
        });
        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->string('provenance', 32)->default('staff_upload');
            $table->string('scan_status', 16)->default('unscanned');
        });
        Schema::create('public_intake_keys', function (Blueprint $table): void {
            $table->string('token_hash', 64)->primary();
            $table->string('session_hash', 64);
            $table->foreignId('inquiry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
        });
        Schema::create('public_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->unique()->constrained()->restrictOnDelete();
            $table->string('idempotency_hash', 64)->unique();
            $table->string('session_hash', 64);
            $table->jsonb('snapshot');
            $table->timestampTz('received_at');
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION protect_public_submission() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Original website submissions are immutable''; END;'; CREATE TRIGGER public_submission_immutable BEFORE UPDATE OR DELETE ON public_submissions FOR EACH ROW EXECUTE FUNCTION protect_public_submission()");
        Schema::create('mailbox_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
            $table->string('email');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('transport_state', 24)->default('disabled');
            $table->text('template_body');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('transport_submitted_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE mailbox_verifications ADD CONSTRAINT verification_transport_check CHECK (transport_state IN ('disabled','unavailable','queued','dispatching','transport_submitted','failed'))");
        DB::statement('CREATE UNIQUE INDEX verification_one_active ON mailbox_verifications (inquiry_id) WHERE invalidated_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_verifications');
        Schema::dropIfExists('public_submissions');
        Schema::dropIfExists('public_intake_keys');
        DB::statement('DROP FUNCTION protect_public_submission()');
        Schema::table('inquiry_documents', fn (Blueprint $table) => $table->dropColumn(['provenance', 'scan_status']));
        Schema::table('inquiries', fn (Blueprint $table) => $table->dropColumn('public_contact'));
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('public_intake_owner_id');
            $table->dropColumn(['public_intake_enabled', 'public_service_intro', 'public_contact_email', 'public_contact_phone', 'public_contact_address', 'public_privacy_notice', 'public_privacy_version', 'receipt_mail_enabled', 'receipt_mail_body']);
        });
    }
};
