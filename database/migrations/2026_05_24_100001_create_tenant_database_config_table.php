<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_database_config', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->string('isolation_level')->default('shared');
            $table->string('database_name')->nullable();
            $table->string('schema_name')->nullable();
            $table->string('provisioning_status')->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_database_config');
    }
};
