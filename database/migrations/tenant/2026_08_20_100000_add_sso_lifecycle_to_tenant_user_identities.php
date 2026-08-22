<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAVE-2 GAP-007: durable Linked / Login Verified / Ready evidence on the identity binding.
 * Existing rows are backfilled as Linked only (not Ready) — pre-production disposable.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('tenant_user_identities', function (Blueprint $table) {
            $table->timestamp('linked_at')->nullable()->after('email_at_link');
            $table->string('verification_status', 32)->nullable()->after('linked_at');
            $table->uuid('linked_idp_configuration_version_id')->nullable()->after('verification_status');
            $table->timestamp('login_verified_at')->nullable()->after('linked_idp_configuration_version_id');
            $table->timestamp('ready_at')->nullable()->after('login_verified_at');
            $table->uuid('ready_idp_configuration_version_id')->nullable()->after('ready_at');
            $table->string('ready_canonical_email')->nullable()->after('ready_idp_configuration_version_id');
            $table->timestamp('last_verification_failure_at')->nullable()->after('ready_canonical_email');
            $table->string('last_verification_failure_reason', 64)->nullable()->after('last_verification_failure_at');
        });

        DB::connection('tenant')->table('tenant_user_identities')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::connection('tenant')->table('tenant_user_identities')->where('id', $row->id)->update([
                    'linked_at' => $row->created_at ?? now(),
                    'verification_status' => 'linked',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('tenant_user_identities', function (Blueprint $table) {
            $table->dropColumn([
                'linked_at',
                'verification_status',
                'linked_idp_configuration_version_id',
                'login_verified_at',
                'ready_at',
                'ready_idp_configuration_version_id',
                'ready_canonical_email',
                'last_verification_failure_at',
                'last_verification_failure_reason',
            ]);
        });
    }
};
