<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-008 — incremental tenant migrations must not destroy existing dedicated DB rows. */
class TenantLayerMigrationRunnerTest extends TestCase
{
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restoreSharedTenantConnectionDatabase();
        $this->setUpDedicatedTenantMode();
    }

    protected function tearDown(): void
    {
        $this->restoreSharedTenantConnectionDatabase();
        parent::tearDown();
    }

    protected function dedicatedTenantConnection(): string
    {
        return (string) config('tenancy_storage.shared_connection', 'tenant');
    }

    protected function useDedicatedPhysicalDatabase(): void
    {
        Config::set('database.connections.'.$this->dedicatedTenantConnection().'.database', self::DEDICATED_DB_A);
    }

    public function test_incremental_run_migrations_preserves_existing_rows_on_dedicated_db(): void
    {
        $email = 'runner-preserve-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        $runner = app(TenantLayerMigrationRunner::class);
        $tenantConnection = $this->dedicatedTenantConnection();

        $this->useDedicatedPhysicalDatabase();

        $this->assertTrue(
            DB::connection($connection)->table('users')->where('email', $email)->exists(),
            'Fixture user must exist before incremental migration.'
        );

        Schema::connection($tenantConnection)->dropIfExists('tenant_user_identities');
        Schema::connection($tenantConnection)->dropIfExists('tenant_sso_config_versions');
        Schema::connection($tenantConnection)->dropIfExists('tenant_sso_config');
        DB::connection($tenantConnection)->table('migrations')->whereIn('migration', [
            '2026_07_07_100000_create_tenant_sso_config_table',
            '2026_07_07_100001_create_tenant_user_identities_table',
            '2026_07_19_100000_create_tenant_sso_config_versions_table',
        ])->delete();

        $this->assertFalse(Schema::connection($tenantConnection)->hasTable('tenant_sso_config'));
        $this->assertTrue($runner->databaseHasAnyTable(Schema::connection($tenantConnection)));
        $this->assertTrue($runner->hasMissingRequiredTables(Schema::connection($tenantConnection)));

        $runner->runMigrations(self::DEDICATED_DB_A);

        $this->useDedicatedPhysicalDatabase();
        $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_sso_config'));
        $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_sso_config_versions'));
        $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_user_identities'));
        $this->assertTrue(
            DB::connection($connection)->table('users')->where('email', $email)->exists(),
            'Existing user row must survive incremental migration.'
        );
    }

    public function test_ensure_migrated_uses_incremental_path_when_database_already_has_tables(): void
    {
        $email = 'runner-policy-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        $runner = app(TenantLayerMigrationRunner::class);
        $tenantConnection = $this->dedicatedTenantConnection();

        $this->useDedicatedPhysicalDatabase();
        $schema = Schema::connection($tenantConnection);

        $this->assertTrue($runner->databaseHasAnyTable($schema));
        $this->assertTrue($runner->hasEstablishedTenantTables($schema));

        Schema::connection($tenantConnection)->dropIfExists('tenant_user_identities');
        Schema::connection($tenantConnection)->dropIfExists('tenant_sso_config_versions');
        Schema::connection($tenantConnection)->dropIfExists('tenant_sso_config');
        DB::connection($tenantConnection)->table('migrations')->whereIn('migration', [
            '2026_07_07_100000_create_tenant_sso_config_table',
            '2026_07_07_100001_create_tenant_user_identities_table',
            '2026_07_19_100000_create_tenant_sso_config_versions_table',
        ])->delete();

        $this->assertTrue($runner->hasMissingRequiredTables($schema));

        $runner->ensureMigrated(self::DEDICATED_DB_A);

        $this->useDedicatedPhysicalDatabase();
        $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_sso_config'));
        $this->assertTrue(Schema::connection($tenantConnection)->hasTable('tenant_sso_config_versions'));
        $this->assertTrue(
            DB::connection($connection)->table('users')->where('email', $email)->exists()
        );
    }
}
