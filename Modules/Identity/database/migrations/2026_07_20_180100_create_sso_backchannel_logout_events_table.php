<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 WS7: central jti replay store for OIDC Back-Channel Logout (D26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('sso_backchannel_logout_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('jti_hash', 64)->unique();
            $table->uuid('tenant_id')->index();
            $table->uuid('idp_configuration_version_id')->nullable()->index();
            $table->string('issuer_hash', 64)->nullable();
            $table->string('status', 32)->index(); // processed | rejected
            $table->string('failure_reason', 64)->nullable();
            $table->unsignedInteger('sessions_revoked')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sso_backchannel_logout_events');
    }
};
