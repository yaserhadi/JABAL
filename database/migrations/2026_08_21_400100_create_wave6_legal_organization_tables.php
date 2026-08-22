<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-6 GAP-002: Legal Organization + Business Owner (central).
 * Legal Organization ≠ Tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('legal_organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status', 32)->default('active');
            $table->string('external_reference', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('central')->create('legal_organization_business_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legal_organization_id')->index();
            $table->uuid('user_id')->index(); // canonical Jabal User UUID (TenantUser plane)
            $table->uuid('primary_tenant_id')->nullable()->index();
            $table->string('status', 32)->default('active');
            $table->timestamp('assigned_at');
            $table->uuid('assigned_by')->nullable();
            $table->timestamps();
            $table->foreign('legal_organization_id')->references('id')->on('legal_organizations')->cascadeOnDelete();
            $table->unique(['legal_organization_id', 'user_id'], 'legal_org_owner_unique');
        });

        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->uuid('legal_organization_id')->nullable()->after('commercial_owner_contact_id');
            $table->uuid('offering_id')->nullable()->after('legal_organization_id');
            $table->boolean('setup_grandfathered')->default(false)->after('offering_id');
            $table->index('legal_organization_id');
            $table->index('offering_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['legal_organization_id', 'offering_id', 'setup_grandfathered']);
        });
        Schema::connection('central')->dropIfExists('legal_organization_business_owners');
        Schema::connection('central')->dropIfExists('legal_organizations');
    }
};
