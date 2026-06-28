<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenant_settings', function (Blueprint $table) {
            $table->string('member_removal_mode', 32)->default('permanent')->nullable()->after('branding_logo_url');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('member_removal_mode');
        });
    }
};
