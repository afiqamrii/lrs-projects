<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('agent');
            $table->boolean('is_active')->default(true);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'agent'))");
        DB::statement('CREATE UNIQUE INDEX users_email_normalized_unique ON users (lower(email))');

        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('display_name')->default('LRS');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->string('currency', 3)->default('MYR');
            $table->timestamps();
        });
        DB::table('company_settings')->insert(['id' => 1, 'display_name' => 'LRS', 'timezone' => 'Asia/Kuala_Lumpur', 'currency' => 'MYR', 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT company_singleton CHECK (id = 1)');

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('display_name')->nullable();
            $table->string('type', 32);
            $table->jsonb('services')->default('[]');
            $table->text('coverage')->nullable();
            $table->text('minimum_notes')->nullable();
            $table->string('communication_channel', 24)->default('email');
            $table->text('internal_notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_check CHECK (type IN ('shipping_line','freight_forwarder','consolidator','transporter','other'))");
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX contacts_vendor_email_unique ON contacts (vendor_id, lower(email))');
        DB::statement('CREATE UNIQUE INDEX contacts_one_primary_per_vendor ON contacts (vendor_id) WHERE is_primary = true');
        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_email_normalized CHECK (email = lower(trim(email)))');
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('record_label');
            $table->jsonb('changes');
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('company_settings');
        DB::statement('DROP INDEX users_email_normalized_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
    }
};
