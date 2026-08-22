<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-5: Mandatory Enrollment + exception closure mode on tenant security policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_security_policies', function (Blueprint $table) {
            $table->boolean('mandatory_sso_enrollment')->default(false)->after('authentication_policy');
            $table->string('sso_exception_closure_mode', 32)->default('automatic')->after('mandatory_sso_enrollment');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_security_policies', function (Blueprint $table) {
            $table->dropColumn(['mandatory_sso_enrollment', 'sso_exception_closure_mode']);
        });
    }
};
