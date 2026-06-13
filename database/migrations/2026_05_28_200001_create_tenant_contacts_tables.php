<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('preferred_language', 10)->nullable();
            $table->string('preferred_channel', 32)->nullable();
            $table->string('status', 32)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::connection('central')->create('tenant_contact_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('central')->create('tenant_contact_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_contact_id');
            $table->uuid('tenant_contact_role_id');
            $table->boolean('is_primary_for_role')->default(false);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->foreign('tenant_contact_id')->references('id')->on('tenant_contacts')->cascadeOnDelete();
            $table->foreign('tenant_contact_role_id')->references('id')->on('tenant_contact_roles')->cascadeOnDelete();
            $table->unique(['tenant_contact_id', 'tenant_contact_role_id'], 'tenant_contact_role_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_contact_role_assignments');
        Schema::connection('central')->dropIfExists('tenant_contact_roles');
        Schema::connection('central')->dropIfExists('tenant_contacts');
    }
};
