<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-5: Per-User Enforcement Exceptions + Temporary Password recovery windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_enforcement_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('reason', 512);
            $table->string('status', 32)->default('active')->index();
            $table->string('closure_mode', 32)->default('automatic');
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->string('close_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('temporary_password_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('reason', 512);
            $table->string('status', 32)->default('active')->index();
            $table->string('classification', 32); // availability | compromise
            $table->string('created_by_type', 32); // platform | tenant
            $table->uuid('created_by_id')->nullable();
            $table->uuid('pea_case_id')->nullable()->index();
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_password_recoveries');
        Schema::dropIfExists('sso_enforcement_exceptions');
    }
};
