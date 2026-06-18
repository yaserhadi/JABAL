<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Application membership authority (ADR-0007 R11).
 * Replaces central tenant_users for auth/membership checks.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->enum('membership_type', ['owner', 'admin', 'member', 'customer']);
            $table->enum('status', ['active', 'invited', 'suspended']);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('membership_type');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('memberships');
    }
};
