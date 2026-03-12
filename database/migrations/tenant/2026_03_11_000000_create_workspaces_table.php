<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3A: First domain table in jabal_tenant_shared.
 *
 * No FK to tenants — PostgreSQL does not support cross-database FKs
 * (jabal_tenant_shared vs jabal_central). Validation via app-level
 * tenancy context, BelongsToTenant, and tests.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('workspaces');
    }
};
