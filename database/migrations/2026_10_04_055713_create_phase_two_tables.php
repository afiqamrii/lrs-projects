<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('reference_identifier')->nullable();
            $table->text('address')->nullable();
            $table->text('internal_notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->index('client_id');
            $table->string('name');
            $table->string('email');
            $table->string('phone', 64)->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['client_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX client_contact_email_unique ON client_contacts (client_id, lower(email))');
        DB::statement('CREATE UNIQUE INDEX client_contact_one_primary ON client_contacts (client_id) WHERE is_active AND is_primary');
        DB::statement('ALTER TABLE client_contacts ADD CONSTRAINT client_contact_normalized CHECK (email = lower(trim(email))), ADD CONSTRAINT client_contact_primary_active CHECK (NOT is_primary OR is_active)');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS inquiry_reference_seq AS bigint');
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->index('client_id');
            $table->foreignId('client_contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('client_contact_id');
            $table->string('title');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->index('owner_id');
            $table->string('priority', 16)->default('normal');
            $table->string('status', 32)->default('draft')->index();
            $table->timestampTz('received_at');
            $table->timestampTz('response_due_at')->nullable()->index();
            $table->string('source_channel', 16);
            $table->text('original_source_text')->nullable();
            $table->text('internal_notes')->nullable();
            $table->jsonb('shipment')->default('{}');
            $table->unsignedInteger('shipment_revision')->default(1);
            $table->unsignedInteger('lock_version')->default(0);
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->foreign(['client_id', 'client_contact_id'])->references(['client_id', 'id'])->on('client_contacts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE inquiries ADD CONSTRAINT inquiry_status_check CHECK (status IN ('draft','needs_review','needs_client_information','ready_for_sourcing','on_hold','closed')), ADD CONSTRAINT inquiry_priority_check CHECK (priority IN ('normal','urgent')), ADD CONSTRAINT inquiry_revision_positive CHECK (shipment_revision > 0 AND lock_version >= 0)");
        Schema::create('shipment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
            $table->unsignedInteger('number');
            $table->jsonb('snapshot');
            $table->string('snapshot_hash', 64);
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->index('reviewer_id');
            $table->string('reviewer_name');
            $table->timestampTz('confirmed_at');
            $table->unique(['inquiry_id', 'number']);
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION protect_shipment_version() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Confirmed shipment versions are immutable''; END;'; CREATE TRIGGER shipment_version_immutable BEFORE UPDATE OR DELETE ON shipment_versions FOR EACH ROW EXECUTE FUNCTION protect_shipment_version()");
        Schema::create('clarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
            $table->unsignedInteger('shipment_revision');
            $table->string('shipment_hash', 64);
            $table->foreignId('client_contact_id')->constrained()->restrictOnDelete();
            $table->index('client_contact_id');
            $table->string('recipient_email');
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index('approved_by');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('communicated_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE clarifications ADD CONSTRAINT clarification_status_check CHECK (status IN ('draft','approved','invalidated','communicated'))");
        Schema::create('inquiry_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->index('author_id');
            $table->string('author_name');
            $table->foreignId('clarification_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('channel', 16);
            $table->string('recipient')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('notes');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('inquiry_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
            $table->foreignId('uploader_id')->constrained('users')->restrictOnDelete();
            $table->index('uploader_id');
            $table->string('uploader_name');
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->string('classification', 32);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
            $table->unique(['inquiry_id', 'checksum']);
        });
        Schema::create('communication_document', function (Blueprint $table) {
            $table->foreignId('inquiry_communication_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_communication_id');
            $table->foreignId('inquiry_document_id')->constrained()->restrictOnDelete();
            $table->index('inquiry_document_id');
            $table->primary(['inquiry_communication_id', 'inquiry_document_id']);
        });
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('client_id');
            $table->foreignId('inquiry_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('inquiry_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inquiry_id');
            $table->dropConstrainedForeignId('client_id');
        });
        foreach (['communication_document', 'inquiry_documents', 'inquiry_communications', 'clarifications', 'shipment_versions', 'inquiries', 'client_contacts', 'clients'] as $name) {
            Schema::dropIfExists($name);
        }
        DB::statement('DROP FUNCTION protect_shipment_version()');
        DB::statement('DROP SEQUENCE inquiry_reference_seq');
    }
};
