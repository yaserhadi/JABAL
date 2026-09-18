<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BK-125 Wave 8 — soft-deleted Tenants must not permanently block handle reuse.
 * Historical handle remains on tenant_handle_allocations; active slug cleared on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: drop NOT NULL without doctrine/dbal.
        DB::connection('central')->statement('ALTER TABLE tenants ALTER COLUMN slug DROP NOT NULL');
    }

    public function down(): void
    {
        // Only restore NOT NULL if no nulls remain.
        $nulls = DB::connection('central')->table('tenants')->whereNull('slug')->count();
        if ($nulls === 0) {
            DB::connection('central')->statement('ALTER TABLE tenants ALTER COLUMN slug SET NOT NULL');
        }
    }
};
