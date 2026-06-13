<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tenant-layer MFA (ADR-0007 R8 — Wave 3 4B-1 rewrite). */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('user_mfa', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('tenant')->create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('user_mfa_recovery_codes');
        Schema::connection('tenant')->dropIfExists('user_mfa');
    }
};
