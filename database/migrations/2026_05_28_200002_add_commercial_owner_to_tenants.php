<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->uuid('commercial_owner_contact_id')->nullable()->after('created_by');
            $table->foreign('commercial_owner_contact_id')
                ->references('id')
                ->on('tenant_contacts')
                ->nullOnDelete();
        });

        Schema::connection('central')->create('tenant_ownerships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('owner_contact_id');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('transfer_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('owner_contact_id')->references('id')->on('tenant_contacts')->restrictOnDelete();
            $table->index(['tenant_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_ownerships');

        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->dropForeign(['commercial_owner_contact_id']);
            $table->dropColumn('commercial_owner_contact_id');
        });
    }
};
