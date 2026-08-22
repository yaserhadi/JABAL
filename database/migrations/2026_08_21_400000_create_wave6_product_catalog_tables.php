<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-6 GAP-001: Product / Capability / Offering catalog (central).
 * Reuses existing plans + entitlements; does not replace Billing Plan/Subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('central')->create('capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('entitlement_code', 64)->nullable()->index(); // maps to Billing entitlement code
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('central')->create('product_capabilities', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('capability_id');
            $table->primary(['product_id', 'capability_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('capability_id')->references('id')->on('capabilities')->cascadeOnDelete();
        });

        Schema::connection('central')->create('offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->uuid('product_id');
            $table->uuid('plan_id')->nullable()->index(); // Billing Plan SKU
            $table->string('status', 32)->default('draft'); // draft|published|retired
            $table->unsignedInteger('version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::connection('central')->create('offering_capabilities', function (Blueprint $table) {
            $table->uuid('offering_id');
            $table->uuid('capability_id');
            $table->boolean('included')->default(true);
            $table->primary(['offering_id', 'capability_id']);
            $table->foreign('offering_id')->references('id')->on('offerings')->cascadeOnDelete();
            $table->foreign('capability_id')->references('id')->on('capabilities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('offering_capabilities');
        Schema::connection('central')->dropIfExists('offerings');
        Schema::connection('central')->dropIfExists('product_capabilities');
        Schema::connection('central')->dropIfExists('capabilities');
        Schema::connection('central')->dropIfExists('products');
    }
};
