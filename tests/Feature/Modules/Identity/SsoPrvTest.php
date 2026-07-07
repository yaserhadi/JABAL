<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\SsoConfigService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-008 PRV — tenant-layer SSO storage in shared_db and database_per_tenant modes. */
class SsoPrvTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreSharedTenantConnectionDatabase();
    }

    protected function tearDown(): void
    {
        $this->restoreSharedTenantConnectionDatabase();
        parent::tearDown();
    }

    protected function registerDedicatedConnection(string $connectionName, string $databaseName): void
    {
        Config::set('database.connections.'.$connectionName, array_merge(
            config('database.connections.tenant'),
            ['database' => $databaseName]
        ));
        DB::purge($connectionName);
    }

    #[Test]
    public function sso_models_declare_tenant_connection(): void
    {
        $this->assertSame('tenant', (new TenantSsoConfig)->getConnectionName());
        $this->assertSame('tenant', (new TenantUserIdentity)->getConnectionName());
    }

    #[Test]
    public function shared_db_sso_config_is_stored_on_tenant_connection_only(): void
    {
        $tenant = \Modules\Tenancy\Models\Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $this->assertTrue(
            DB::connection('tenant')->table('tenant_sso_config')->where('tenant_id', $tenant->id)->exists()
        );
        $this->assertFalse(
            DB::connection('central')->getSchemaBuilder()->hasTable('tenant_sso_config')
        );
    }

    #[Test]
    public function database_per_tenant_sso_rows_live_on_dedicated_physical_database(): void
    {
        $this->setUpDedicatedTenantMode();
        $this->restoreSharedTenantConnectionDatabase();

        $tenant = Tenant::factory()->create([
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ]);

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => self::DEDICATED_DB_A,
            'provisioning_status' => 'active',
        ]);

        $connection = 'tenant_db_'.$tenant->id;
        $this->registerDedicatedConnection($connection, self::DEDICATED_DB_A);

        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $this->assertSame('jabal_tenant_shared_testing', (string) config('database.connections.'.$sharedConnection.'.database'));

        tenancy()->initialize($tenant->fresh(['databaseConfig']));
        $activeConnection = app(TenantStorageResolver::class)->connectionFor($tenant);
        $this->assertSame($connection, $activeConnection);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dedicated SSO User',
            'email' => 'ded-sso-prv-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        TenantSsoConfig::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'enabled' => true,
                'issuer_url' => 'https://dedicated-idp.example.com',
                'client_id' => 'client-id',
                'client_secret_encrypted' => Crypt::encryptString('secret'),
            ]
        );
        $identity = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://dedicated-idp.example.com',
            'subject' => 'ded-sub-'.Str::uuid()->toString(),
        ]);
        tenancy()->end();

        $this->assertTrue(
            DB::connection($connection)->table('tenant_sso_config')->where('tenant_id', $tenant->id)->exists()
        );
        $this->assertTrue(
            DB::connection($connection)->table('tenant_user_identities')->where('id', $identity->id)->exists()
        );

        $sharedDb = DB::connection($sharedConnection)->selectOne('select current_database() as db');
        $dedicatedDb = DB::connection($connection)->selectOne('select current_database() as db');
        $this->assertNotSame(
            $sharedDb->db,
            $dedicatedDb->db,
            'Shared and dedicated testing databases must be physically separate. Run: php tests/Support/ensure_dedicated_test_databases.php'
        );

        $this->assertFalse(
            DB::connection($sharedConnection)->table('tenant_sso_config')->where('tenant_id', $tenant->id)->exists(),
            'SSO config must not be written to shared tenant database for dedicated tenants.'
        );
        $this->assertFalse(
            DB::connection($sharedConnection)->table('tenant_user_identities')->where('id', $identity->id)->exists(),
            'Identity links must not be written to shared tenant database for dedicated tenants.'
        );
    }

    #[Test]
    public function tenant_layer_migration_runner_preserves_existing_rows_when_sso_tables_reapplied(): void
    {
        $this->setUpDedicatedTenantMode();

        $email = 'sso-runner-prv-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        $runner = app(\App\Support\Tenancy\TenantLayerMigrationRunner::class);
        $tenantConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $originalSharedDatabase = config('database.connections.'.$tenantConnection.'.database');

        try {
            Config::set('database.connections.'.$tenantConnection.'.database', self::DEDICATED_DB_A);

            $this->assertTrue(
                DB::connection($connection)->table('users')->where('email', $email)->exists()
            );

            Schema::connection($tenantConnection)->dropIfExists('tenant_user_identities');
            Schema::connection($tenantConnection)->dropIfExists('tenant_sso_config');
            DB::connection($tenantConnection)->table('migrations')->whereIn('migration', [
                '2026_07_07_100000_create_tenant_sso_config_table',
                '2026_07_07_100001_create_tenant_user_identities_table',
            ])->delete();

            $runner->runMigrations(self::DEDICATED_DB_A);

            $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_sso_config'));
            $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_user_identities'));
            $this->assertTrue(
                DB::connection($connection)->table('users')->where('email', $email)->exists(),
                'Existing tenant user must survive incremental SSO migration.'
            );
        } finally {
            Config::set('database.connections.'.$tenantConnection.'.database', $originalSharedDatabase);
            DB::purge($tenantConnection);
            $this->restoreSharedTenantConnectionDatabase();
        }
    }

    #[Test]
    public function cross_tenant_identity_links_do_not_leak_between_dedicated_databases(): void
    {
        $this->setUpDedicatedTenantMode();
        $this->restoreSharedTenantConnectionDatabase();

        $tenantA = Tenant::factory()->create([
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ]);
        $tenantB = Tenant::factory()->create([
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ]);

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenantA->id,
            'isolation_level' => 'database',
            'database_name' => self::DEDICATED_DB_A,
            'provisioning_status' => 'active',
        ]);
        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenantB->id,
            'isolation_level' => 'database',
            'database_name' => 'jabal_tenant_dedicated_b_testing',
            'provisioning_status' => 'active',
        ]);

        $connectionA = 'tenant_db_'.$tenantA->id;
        $connectionB = 'tenant_db_'.$tenantB->id;
        $this->registerDedicatedConnection($connectionA, self::DEDICATED_DB_A);
        $this->registerDedicatedConnection($connectionB, 'jabal_tenant_dedicated_b_testing');

        tenancy()->initialize($tenantA->fresh(['databaseConfig']));
        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A User',
            'email' => 'tenant-a-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $identityA = TenantUserIdentity::query()->create([
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'issuer' => 'https://idp-a.example.com',
            'subject' => 'sub-a-'.Str::uuid()->toString(),
        ]);
        tenancy()->end();

        $this->assertTrue(
            DB::connection($connectionA)->table('tenant_user_identities')->where('id', $identityA->id)->exists()
        );
        $this->assertFalse(
            DB::connection($connectionB)->table('tenant_user_identities')->where('id', $identityA->id)->exists(),
            'Tenant A identity link must not appear in tenant B dedicated database.'
        );

        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $this->assertFalse(
            DB::connection($sharedConnection)->table('tenant_user_identities')->where('id', $identityA->id)->exists(),
            'Dedicated tenant identity link must not appear in shared tenant database.'
        );
    }
}
