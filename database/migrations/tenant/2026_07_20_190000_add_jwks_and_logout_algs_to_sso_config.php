<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 WS7 corrective: trusted JWKS URI + explicit logout-token signing alg allowlist (D22/D26).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->text('jwks_uri')->nullable()->after('redirect_uri');
            $table->json('logout_token_signing_algs')->nullable()->after('jwks_uri');
        });

        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->text('jwks_uri')->nullable()->after('redirect_uri');
            $table->json('logout_token_signing_algs')->nullable()->after('jwks_uri');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_sso_config', function (Blueprint $table) {
            $table->dropColumn(['jwks_uri', 'logout_token_signing_algs']);
        });

        Schema::connection('tenant')->table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->dropColumn(['jwks_uri', 'logout_token_signing_algs']);
        });
    }
};
