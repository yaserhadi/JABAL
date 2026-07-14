<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Custom tenants schema (matches Modules\Tenancy\Models\Tenant).
     * Stancl's default migration was replaced; we use uuid, name, slug, isolation_level.
     * BK-064: personal|organization type column removed (wipe/reseed pre-live).
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('isolation_level', ['shared', 'schema', 'database'])
                ->default('shared');
            $table->timestamps();
            $table->softDeletes();

            $table->index('isolation_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
