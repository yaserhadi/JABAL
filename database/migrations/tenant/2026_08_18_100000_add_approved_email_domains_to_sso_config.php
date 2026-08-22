<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-1 GAP-015: approved SSO email domains on versioned Connection config.
 * Empty list = fail closed (no implicit allow-all). Not a JIT / User-discovery store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sso_config', function (Blueprint $table) {
            $table->json('approved_email_domains')->nullable()->after('scopes');
        });

        Schema::table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->json('approved_email_domains')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sso_config_versions', function (Blueprint $table) {
            $table->dropColumn('approved_email_domains');
        });

        Schema::table('tenant_sso_config', function (Blueprint $table) {
            $table->dropColumn('approved_email_domains');
        });
    }
};
