<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3D: Central tenant settings (1:1 with tenants).
     *
     * @see Phase 3D plan — scope lock on columns.
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('display_name')->nullable();
            $table->string('timezone')->nullable();
            $table->string('locale', 32)->nullable();
            $table->string('branding_logo_url', 2048)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_settings');
    }
};
