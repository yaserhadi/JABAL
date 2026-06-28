<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('memberships', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('joined_at');
        });

        $driver = Schema::connection('tenant')->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('tenant')->statement('ALTER TABLE memberships DROP CONSTRAINT IF EXISTS memberships_status_check');
            DB::connection('tenant')->statement(
                "ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (((status)::text = ANY (ARRAY['active'::character varying, 'invited'::character varying, 'suspended'::character varying, 'removed'::character varying]::text[])))"
            );
        } elseif ($driver === 'mysql') {
            DB::connection('tenant')->statement(
                "ALTER TABLE memberships MODIFY status ENUM('active', 'invited', 'suspended', 'removed') NOT NULL"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::connection('tenant')->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::connection('tenant')->statement('ALTER TABLE memberships DROP CONSTRAINT IF EXISTS memberships_status_check');
            DB::connection('tenant')->statement(
                "ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (((status)::text = ANY (ARRAY['active'::character varying, 'invited'::character varying, 'suspended'::character varying]::text[])))"
            );
        } elseif ($driver === 'mysql') {
            DB::connection('tenant')->statement(
                "ALTER TABLE memberships MODIFY status ENUM('active', 'invited', 'suspended') NOT NULL"
            );
        }

        Schema::connection('tenant')->table('memberships', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
