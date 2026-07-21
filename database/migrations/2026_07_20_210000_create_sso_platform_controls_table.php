<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BK-082 WS8: Platform-wide Enterprise SSO kill switches (central, D34).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('sso_platform_controls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->boolean('pause_new_initiations')->default(false);
            $table->boolean('disable_enterprise_sso')->default(false);
            $table->timestamps();
        });

        DB::connection('central')->table('sso_platform_controls')->insert([
            'id' => (string) Str::uuid(),
            'pause_new_initiations' => false,
            'disable_enterprise_sso' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sso_platform_controls');
    }
};
