<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-4: binding role + Reset SSO transaction support on tenant_user_identities.
 * current | candidate | historical | security_held
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_user_identities', function (Blueprint $table) {
            $table->string('binding_role', 32)->default('current')->after('user_id');
            $table->timestamp('superseded_at')->nullable()->after('last_verification_failure_reason');
            $table->uuid('superseded_by_identity_id')->nullable()->after('superseded_at');
            $table->timestamp('security_held_at')->nullable()->after('superseded_by_identity_id');
            $table->string('security_held_reason', 64)->nullable()->after('security_held_at');
            $table->index(['tenant_id', 'user_id', 'binding_role']);
        });

        DB::connection('tenant')->table('tenant_user_identities')
            ->whereNull('binding_role')
            ->orWhere('binding_role', '')
            ->update(['binding_role' => 'current']);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_user_identities', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'user_id', 'binding_role']);
            $table->dropColumn([
                'binding_role',
                'superseded_at',
                'superseded_by_identity_id',
                'security_held_at',
                'security_held_reason',
            ]);
        });
    }
};
