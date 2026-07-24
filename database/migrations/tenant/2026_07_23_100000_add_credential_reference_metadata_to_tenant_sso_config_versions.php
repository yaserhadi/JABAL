<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-098: version-owned opaque credential reference metadata (reference-only; no ciphertext).
 *
 * Operational credential authority lives on tenant_sso_config_versions only.
 * Parent tenant_sso_config must not gain a second operational secret reference.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->string('credential_provider', 64)->nullable()->after('client_id');
            $table->string('credential_reference', 512)->nullable()->after('credential_provider');
            $table->string('credential_type', 64)->nullable()->after('credential_reference');
            $table->string('credential_version_policy', 64)->nullable()->after('credential_type');
            $table->string('credential_environment_scope', 64)->nullable()->after('credential_version_policy');
            $table->string('credential_status', 32)->nullable()->after('credential_environment_scope');
            $table->timestamp('credential_last_verified_at')->nullable()->after('credential_status');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->dropColumn([
                'credential_provider',
                'credential_reference',
                'credential_type',
                'credential_version_policy',
                'credential_environment_scope',
                'credential_status',
                'credential_last_verified_at',
            ]);
        });
    }
};
