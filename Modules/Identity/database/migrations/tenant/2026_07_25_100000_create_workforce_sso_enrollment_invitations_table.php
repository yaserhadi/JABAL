<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-099 Scenario B: Workforce SSO enrollment invitation + login resume (tenant layer).
 *
 * Runtime path: database/migrations/tenant (TenantLayerMigrationRunner SSOT).
 * Module-authored artifact mirrors Modules/Identity workforce enrollment schema.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('workforce_sso_enrollment_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('intended_user_id');
            $table->uuid('membership_id');
            $table->uuid('sso_config_id');
            $table->uuid('sso_config_version_id');
            $table->string('tenant_host');
            $table->uuid('issued_by_user_id');
            $table->string('delivery_email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('audit_correlation_id');
            $table->timestamps();

            $table->index(['tenant_id', 'intended_user_id']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::connection('tenant')->create('workforce_sso_enrollment_login_resumes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invitation_id')->index();
            $table->uuid('tenant_id')->index();
            $table->string('tenant_host');
            $table->string('token_hash', 64)->unique();
            $table->string('browser_binding_secret_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('workforce_sso_enrollment_login_resumes');
        Schema::connection('tenant')->dropIfExists('workforce_sso_enrollment_invitations');
    }
};
