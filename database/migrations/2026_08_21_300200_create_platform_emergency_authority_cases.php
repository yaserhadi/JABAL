<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-5: Platform Emergency Authority cases (central).
 * Not ordinary Tenant identity; not Generic Approval Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('platform_emergency_authority_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('platform_user_id')->index();
            $table->string('reason', 512);
            $table->string('classification', 32); // availability | compromise
            $table->string('status', 32)->default('active')->index();
            $table->string('purpose', 128)->default('restore_tenant_admin_control');
            $table->uuid('emergency_tenant_user_id')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_emergency_authority_cases');
    }
};
