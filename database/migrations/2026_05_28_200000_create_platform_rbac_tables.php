<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('platform_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name')->default('platform');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::connection('central')->create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name')->default('platform');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::connection('central')->create('platform_role_has_permissions', function (Blueprint $table) {
            $table->uuid('platform_role_id');
            $table->uuid('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id'], 'platform_role_permission_primary');
            $table->foreign('platform_role_id')->references('id')->on('platform_roles')->cascadeOnDelete();
            $table->foreign('platform_permission_id')->references('id')->on('platform_permissions')->cascadeOnDelete();
        });

        Schema::connection('central')->create('platform_model_has_roles', function (Blueprint $table) {
            $table->uuid('platform_role_id');
            $table->string('model_type');
            $table->uuid('model_id');
            $table->primary(['platform_role_id', 'model_id', 'model_type'], 'platform_model_role_primary');
            $table->index(['model_id', 'model_type']);
            $table->foreign('platform_role_id')->references('id')->on('platform_roles')->cascadeOnDelete();
        });

        Schema::connection('central')->create('platform_model_has_permissions', function (Blueprint $table) {
            $table->uuid('platform_permission_id');
            $table->string('model_type');
            $table->uuid('model_id');
            $table->primary(['platform_permission_id', 'model_id', 'model_type'], 'platform_model_permission_primary');
            $table->index(['model_id', 'model_type']);
            $table->foreign('platform_permission_id')->references('id')->on('platform_permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('platform_model_has_permissions');
        Schema::connection('central')->dropIfExists('platform_model_has_roles');
        Schema::connection('central')->dropIfExists('platform_role_has_permissions');
        Schema::connection('central')->dropIfExists('platform_roles');
        Schema::connection('central')->dropIfExists('platform_permissions');
    }
};
