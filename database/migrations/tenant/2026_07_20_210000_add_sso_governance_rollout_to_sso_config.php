<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 WS8: Tenant SSO rollout / security-disable / pilot controls (D34).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->string('rollout_state', 32)->default('enabled')->after('disabled_by_entitlement');
            $table->timestamp('security_disabled_at')->nullable()->after('rollout_state');
            $table->string('security_disable_reason', 64)->nullable()->after('security_disabled_at');
            $table->json('pilot_user_id_hashes')->nullable()->after('security_disable_reason');
            $table->uuid('pending_version_id')->nullable()->after('active_version_id');
        });

        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable()->after('activated_at');
            $table->timestamp('approved_at')->nullable()->after('validated_at');
            $table->timestamp('disabled_at')->nullable()->after('superseded_at');
            $table->timestamp('secret_revoked_at')->nullable()->after('disabled_at');
            $table->string('disable_reason', 64)->nullable()->after('secret_revoked_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->dropColumn([
                'rollout_state',
                'security_disabled_at',
                'security_disable_reason',
                'pilot_user_id_hashes',
                'pending_version_id',
            ]);
        });

        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->dropColumn([
                'validated_at',
                'approved_at',
                'disabled_at',
                'secret_revoked_at',
                'disable_reason',
            ]);
        });
    }
};
