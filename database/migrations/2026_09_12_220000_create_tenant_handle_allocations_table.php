<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BK-125 Wave 7 / CONFLICT-F — Tenant handle lifecycle allocations.
 *
 * Tenant ID remains durable identity; handle is mutable address with
 * ACTIVE → RETIRED → RELEASABLE → AVAILABLE lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenant_handle_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('handle', 63);
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('status', 32); // active|retired|releasable|available
            $table->boolean('is_canonical')->default(false);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('releasable_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('superseded_by_handle', 63)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'retired_at']);
            $table->index('handle');
        });

        // Blocking statuses cannot share a handle (PostgreSQL partial unique).
        DB::connection('central')->statement(
            'CREATE UNIQUE INDEX tenant_handle_allocations_blocking_unique
             ON tenant_handle_allocations (handle)
             WHERE status IN (\'active\', \'retired\', \'releasable\')'
        );

        // Backfill ACTIVE allocations for existing tenants with slugs.
        $now = now();
        $rows = DB::connection('central')->table('tenants')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            DB::connection('central')->table('tenant_handle_allocations')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'handle' => strtolower((string) $row->slug),
                'tenant_id' => $row->id,
                'status' => 'active',
                'is_canonical' => true,
                'assigned_at' => $now,
                'retired_at' => null,
                'releasable_at' => null,
                'released_at' => null,
                'superseded_by_handle' => null,
                'actor_id' => null,
                'reason' => 'wave7_backfill',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_handle_allocations');
    }
};
