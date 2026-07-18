<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BK-073 — sanctioned addition of domains.data JSON for metadata contract
 * (data.category = platform_subdomain). Owner-approved remediation after
 * preflight STOP: stock Stancl domains migration lacked the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('domains', function (Blueprint $table) {
            $table->json('data')->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('domains', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
