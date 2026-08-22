<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-3 GAP-009: Authentication Policy primitive on TenantSecurityPolicy.
 * Values: password | sso | both. Default both preserves Password + SSO availability
 * without enabling population-wide SSO-only enforcement (WAVE-5).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_security_policies', function (Blueprint $table) {
            $table->string('authentication_policy', 32)->default('both')->after('session_idle_timeout');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_security_policies', function (Blueprint $table) {
            $table->dropColumn('authentication_policy');
        });
    }
};
