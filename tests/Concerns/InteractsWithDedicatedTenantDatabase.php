<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantRbacProvisioner;

trait InteractsWithDedicatedTenantDatabase
{
    protected const DEDICATED_DB_A = 'jabal_tenant_dedicated_a_testing';

    protected const DEDICATED_TENANT_A = '11111111-1111-1111-1111-111111111111';

    protected function setUpDedicatedTenantMode(): void
    {
        config(['tenancy_storage.mode' => 'database_per_tenant']);
        $this->requirePhysicalDatabase(self::DEDICATED_DB_A);
        app(TenantLayerMigrationRunner::class)->ensureMigrated(self::DEDICATED_DB_A);
        $this->registerPhysicalConnection('dedicated_tenant_a', self::DEDICATED_DB_A);
    }

    protected function createDedicatedUserFixture(string $email): array
    {
        $tenant = Tenant::query()->find(self::DEDICATED_TENANT_A);
        if (! $tenant) {
            $tenant = new Tenant;
            $tenant->id = self::DEDICATED_TENANT_A;
            $tenant->forceFill([
                'name' => 'Dedicated Auth Org',
                'slug' => 'ded-auth-a',
                'isolation_level' => 'database',
                'status' => 'active',
            ])->save();
        }

        TenantDatabaseConfig::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'isolation_level' => 'database',
                'database_name' => self::DEDICATED_DB_A,
                'provisioning_status' => 'active',
            ]
        );

        $connection = 'tenant_db_'.$tenant->id;
        Config::set('database.connections.'.$connection, array_merge(
            config('database.connections.tenant'),
            ['database' => self::DEDICATED_DB_A]
        ));

        tenancy()->initialize($tenant->fresh(['databaseConfig']));
        try {
            $provisioner = app(TenantRbacProvisioner::class);
            $provisioner->ensureGlobalPermissions();
            $provisioner->ensureRolesForTenant($tenant);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Dedicated User',
                'email' => $email,
                'password' => 'password',
            ]);

            Membership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'membership_type' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $provisioner->assignTenantAdminRole($user, $tenant);

            $resolvedUser = User::withoutGlobalScope('tenant')->findOrFail($user->id);
        } finally {
            tenancy()->end();
        }

        $resolver = app(TenantStorageResolver::class);

        return [$tenant->fresh(['databaseConfig']), $resolvedUser, $resolver->connectionFor($tenant)];
    }

    protected function assertTableRowOnDedicatedNotShared(string $table, array $where, string $dedicatedConnection): void
    {
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');

        $this->assertNotSame(
            $dedicatedConnection,
            $sharedConnection,
            'Dedicated connection must differ from shared tenant connection.'
        );

        $this->assertTrue(
            Schema::connection($dedicatedConnection)->hasTable($table),
            "Table [{$table}] must exist on dedicated connection [{$dedicatedConnection}]."
        );

        $this->assertTrue(
            Schema::connection($sharedConnection)->hasTable($table),
            "Table [{$table}] must exist on shared tenant connection [{$sharedConnection}]."
        );

        $this->assertTrue(
            DB::connection($dedicatedConnection)->table($table)->where($where)->exists(),
            'Row must exist on dedicated connection.'
        );

        $this->assertFalse(
            DB::connection($sharedConnection)->table($table)->where($where)->exists(),
            'Equivalent row must not exist on shared tenant connection.'
        );
    }

    protected function requirePhysicalDatabase(string $databaseName): void
    {
        if (! str_ends_with($databaseName, '_testing')) {
            throw new \RuntimeException('Refusing to use non-testing database: '.$databaseName);
        }

        $exists = DB::connection('central')->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$databaseName]
        );

        if (! $exists) {
            $this->markTestSkipped(
                'Dedicated test database ['.$databaseName.'] is missing. Run: php tests/Support/ensure_dedicated_test_databases.php'
            );
        }
    }

    protected function registerPhysicalConnection(string $connectionName, string $databaseName): void
    {
        Config::set('database.connections.'.$connectionName, array_merge(
            config('database.connections.tenant'),
            ['database' => $databaseName]
        ));
    }

    protected function restoreSharedTenantConnectionDatabase(): void
    {
        $connection = (string) config('tenancy_storage.shared_connection', 'tenant');
        Config::set('database.connections.'.$connection.'.database', 'jabal_tenant_shared_testing');
        DB::purge($connection);
    }
}
