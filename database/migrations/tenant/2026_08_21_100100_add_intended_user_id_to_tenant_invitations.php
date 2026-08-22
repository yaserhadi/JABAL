<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-3 GAP-004: J2 Invite binds to existing User UUID (Invite ≠ create User).
 * Legacy rows without intended_user_id fail closed on accept.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_invitations', function (Blueprint $table) {
            $table->uuid('intended_user_id')->nullable()->after('email');
            $table->index(['tenant_id', 'intended_user_id']);
        });

        // Pre-production: expire pending invites that lack User linkage (cannot create User).
        DB::connection('tenant')->table('tenant_invitations')
            ->whereNull('intended_user_id')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update([
                'expires_at' => now()->subMinute(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_invitations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'intended_user_id']);
            $table->dropColumn('intended_user_id');
        });
    }
};
