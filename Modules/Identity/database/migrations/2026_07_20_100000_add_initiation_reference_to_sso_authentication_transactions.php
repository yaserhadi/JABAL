<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-082 WS3: opaque Auth Host initiation reference + recoverable state secret for authorize URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('sso_authentication_transactions', function (Blueprint $table) {
            $table->uuid('initiation_lookup')->nullable()->unique()->after('state_secret_hash');
            $table->string('initiation_secret_hash', 64)->nullable()->after('initiation_lookup');
            $table->text('state_secret_encrypted')->nullable()->after('initiation_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('sso_authentication_transactions', function (Blueprint $table) {
            $table->dropColumn(['initiation_lookup', 'initiation_secret_hash', 'state_secret_encrypted']);
        });
    }
};
