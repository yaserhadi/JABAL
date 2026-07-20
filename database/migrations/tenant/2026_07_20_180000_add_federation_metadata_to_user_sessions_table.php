<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 WS7: federation metadata on UserSession for D26 Back-Channel Logout scoping.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('user_sessions', function (Blueprint $table) {
            $table->string('idp_sid')->nullable()->after('session_id');
            $table->text('idp_issuer')->nullable()->after('idp_sid');
            $table->uuid('identity_link_id')->nullable()->after('idp_issuer');
            $table->uuid('idp_configuration_version_id')->nullable()->after('identity_link_id');
            $table->uuid('correlation_id')->nullable()->after('idp_configuration_version_id');

            $table->index(['tenant_id', 'idp_sid']);
            $table->index(['tenant_id', 'identity_link_id']);
            $table->index(['tenant_id', 'correlation_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('user_sessions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'idp_sid']);
            $table->dropIndex(['tenant_id', 'identity_link_id']);
            $table->dropIndex(['tenant_id', 'correlation_id']);
            $table->dropColumn([
                'idp_sid',
                'idp_issuer',
                'identity_link_id',
                'idp_configuration_version_id',
                'correlation_id',
            ]);
        });
    }
};
