<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-4: Reset SSO / IdP migration transactions (candidate vs current).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('sso_identity_reset_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('initiated_by_user_id');
            $table->string('purpose', 64); // reset_sso | email_change | idp_migration_a | idp_migration_b
            $table->string('status', 32)->default('pending'); // pending | completed | cancelled | failed
            $table->uuid('current_identity_id')->nullable();
            $table->uuid('candidate_identity_id')->nullable();
            $table->boolean('compromised_current')->default(false);
            $table->boolean('same_euid_reverification')->default(false);
            $table->string('target_issuer')->nullable();
            $table->uuid('target_idp_configuration_version_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::connection('tenant')->create('sso_identity_binding_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('identity_id')->nullable()->index();
            $table->uuid('reset_transaction_id')->nullable()->index();
            $table->text('issuer');
            $table->string('subject');
            $table->string('email_at_link')->nullable();
            $table->string('verification_status', 32)->nullable();
            $table->string('binding_role', 32)->nullable();
            $table->string('event', 64); // superseded | security_held | snapshot | promoted
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('user_canonical_email_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('initiated_by_user_id');
            $table->string('current_email');
            $table->string('proposed_email');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 32)->default('pending'); // pending | verified | completed | cancelled | expired
            $table->boolean('requires_reset_sso')->default(false);
            $table->uuid('reset_transaction_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('user_canonical_email_change_requests');
        Schema::connection('tenant')->dropIfExists('sso_identity_binding_history');
        Schema::connection('tenant')->dropIfExists('sso_identity_reset_transactions');
    }
};
