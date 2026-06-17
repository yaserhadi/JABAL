<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenant-level MFA policy override (central metadata — not auth runtime). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_security_policies', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->boolean('mfa_required')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_security_policies');
    }
};
