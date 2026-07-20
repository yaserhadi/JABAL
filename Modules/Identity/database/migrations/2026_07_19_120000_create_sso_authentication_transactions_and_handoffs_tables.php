<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 / DEC-0024: Identity-owned central Authentication Transaction + Tenant Handoff store.
 *
 * Allowlisted central SSO tables (SsoScopeGuardTest). Tenant SSO config remains on tenant layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('sso_authentication_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('domain_id')->nullable()->index();
            $table->string('destination_host');
            $table->string('addressing_profile', 16);
            $table->string('post_login_path', 2048);
            $table->uuid('idp_configuration_version_id')->index();
            $table->text('expected_issuer')->nullable();
            $table->string('purpose', 32)->default('ordinary');
            $table->uuid('state_lookup')->unique();
            $table->string('state_secret_hash', 64);
            $table->text('nonce_encrypted');
            $table->text('pkce_verifier_encrypted');
            $table->string('auth_binding_secret_hash', 64)->nullable();
            $table->string('tenant_continuation_secret_hash', 64)->nullable();
            $table->string('status', 32)->index();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('callback_reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('secrets_erased_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'idp_configuration_version_id', 'status']);
            $table->index(['tenant_continuation_secret_hash', 'status']);
        });

        Schema::connection('central')->create('sso_tenant_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id')->index();
            $table->uuid('transaction_id');
            $table->uuid('tenant_id')->index();
            $table->uuid('domain_id')->nullable()->index();
            $table->string('destination_host');
            $table->string('audience', 64);
            $table->string('post_login_path', 2048);
            $table->string('secret_hash', 64);
            $table->string('tenant_continuation_secret_hash', 64);
            $table->uuid('user_id')->nullable();
            $table->uuid('identity_link_id')->nullable();
            $table->json('assurance_evidence')->nullable();
            $table->string('status', 32)->index();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('secrets_erased_at')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('id')
                ->on('sso_authentication_transactions')
                ->cascadeOnDelete();

            $table->unique(['transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sso_tenant_handoffs');
        Schema::connection('central')->dropIfExists('sso_authentication_transactions');
    }
};
