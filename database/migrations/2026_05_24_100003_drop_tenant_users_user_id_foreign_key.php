<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_users.user_id references tenant-application user UUID (tenant DB), not central users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenant_users', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_users', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
