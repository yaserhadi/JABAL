<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-1 GAP-006: carry encrypted IdP email on enrollment continuation (erased on consume).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('sso_enrollment_continuations', function (Blueprint $table) {
            $table->text('idp_email_encrypted')->nullable()->after('subject_encrypted');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('sso_enrollment_continuations', function (Blueprint $table) {
            $table->dropColumn('idp_email_encrypted');
        });
    }
};
