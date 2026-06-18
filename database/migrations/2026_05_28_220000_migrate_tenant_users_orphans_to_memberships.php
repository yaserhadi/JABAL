<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Copy any remaining central tenant_users rows into tenant-layer memberships (§9.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('tenant_users')) {
            return;
        }

        $rows = DB::connection('central')->table('tenant_users')->get();

        foreach ($rows as $row) {
            $exists = DB::connection('tenant')->table('memberships')
                ->where('tenant_id', $row->tenant_id)
                ->where('user_id', $row->user_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection('tenant')->table('memberships')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $row->tenant_id,
                'user_id' => $row->user_id,
                'membership_type' => $row->membership_type,
                'status' => $row->status,
                'joined_at' => $row->joined_at,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data migration is forward-only; rollback of drop migration restores schema only.
    }
};
