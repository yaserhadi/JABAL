<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Drop central tenant_users bridge — authority is tenant-layer memberships (§9.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->dropIfExists('tenant_users');
    }

    public function down(): void
    {
        Schema::connection('central')->create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->enum('membership_type', ['owner', 'admin', 'member', 'customer']);
            $table->enum('status', ['active', 'invited', 'suspended']);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->index('membership_type');
            $table->index('status');
        });
    }
};
