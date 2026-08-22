<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-6 GAP-003: Tenant Setup Framework (central definitions + tenant state).
 * Tenant Active ≠ Operationally Ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('setup_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('requirement_type', 32); // blocking|optional|conditional
            $table->string('capability_code', 64)->nullable()->index();
            $table->string('condition_entitlement_code', 64)->nullable();
            $table->uuid('product_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code', 'version']);
        });

        Schema::connection('central')->create('tenant_setup_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('setup_definition_id')->index();
            $table->unsignedInteger('definition_version');
            $table->string('status', 32)->default('pending'); // pending|completed|not_applicable
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'setup_definition_id'], 'tenant_setup_def_unique');
            $table->foreign('setup_definition_id')->references('id')->on('setup_definitions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_setup_states');
        Schema::connection('central')->dropIfExists('setup_definitions');
    }
};
