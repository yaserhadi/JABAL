<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-028 / DEC-0011: Tenant-owned operational settings on tenant data layer.
 *
 * @see Modules\Tenancy\Models\AppSetting
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('app_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('display_name')->nullable();
            $table->string('timezone')->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('branding_logo_url', 2048)->nullable();
            $table->string('member_removal_mode', 32)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('app_settings');
    }
};
