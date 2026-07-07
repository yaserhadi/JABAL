<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-008: Tenant-owned OIDC SSO configuration (one row per organization tenant).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('tenant_sso_config', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('disabled_by_entitlement')->default(false);
            $table->string('provider_label')->nullable();
            $table->text('issuer_url')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret_encrypted')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('tenant_sso_config');
    }
};
