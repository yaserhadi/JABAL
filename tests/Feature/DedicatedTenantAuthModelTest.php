<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\UserMfa;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Tests\TestCase;

/**
 * BK-006 Track B — auth models on dedicated physical DB (DEC-0007 AuthModelBoundary).
 */
class DedicatedTenantAuthModelTest extends TestCase
{
    private const DB_A = 'jabal_tenant_dedicated_a_testing';

    private const TENANT_A = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy_storage.mode' => 'database_per_tenant']);
        $this->requirePhysicalDatabase(self::DB_A);
        app(TenantLayerMigrationRunner::class)->ensureMigrated(self::DB_A);
        $this->registerPhysicalConnection('dedicated_tenant_a', self::DB_A);
    }

    public function test_dedicated_tenant_user_row_lives_on_physical_db_not_shared(): void
    {
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture('ded-user-'.uniqid().'@example.com');

        $this->assertSame(self::DB_A, config("database.connections.{$connection}.database", self::DB_A));
        $this->assertTrue(
            DB::connection($connection)->table('users')->where('id', $user->id)->exists(),
            'User must exist on dedicated connection'
        );
        $this->assertFalse(
            DB::connection('tenant')->table('users')->where('id', $user->id)->exists(),
            'User must not exist on shared tenant connection'
        );
    }

    public function test_find_for_login_resolves_dedicated_db_user_before_tenancy_init(): void
    {
        $email = 'ded-login-'.uniqid().'@example.com';
        [$tenant, $user] = $this->createDedicatedUserFixture($email);

        tenancy()->end();

        $found = TenantUser::findForLogin($email);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
        $this->assertSame($tenant->id, $found->tenant_id);
    }

    public function test_mfa_record_stored_on_dedicated_db_for_database_tenant(): void
    {
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture('ded-mfa-'.uniqid().'@example.com');

        tenancy()->initialize($tenant);
        try {
            $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);
            app(MfaService::class)->beginEnrollment($user);
            $this->assertTrue(
                UserMfa::query()->where('user_id', $user->id)->exists()
            );
            $this->assertTrue(
                DB::connection($connection)->table('user_mfa')->where('user_id', $user->id)->exists()
            );
            $this->assertFalse(
                DB::connection('tenant')->table('user_mfa')->where('user_id', $user->id)->exists()
            );
        } finally {
            tenancy()->end();
        }
    }

    public function test_membership_row_on_dedicated_db_not_shared(): void
    {
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture('ded-member-'.uniqid().'@example.com');

        $this->assertTrue(
            DB::connection($connection)->table('memberships')->where('user_id', $user->id)->exists()
        );
        $this->assertFalse(
            DB::connection('tenant')->table('memberships')->where('user_id', $user->id)->exists()
        );
    }

    /**
     * @return array{0: Tenant, 1: User, 2: string}
     */
    private function createDedicatedUserFixture(string $email): array
    {
        $tenant = Tenant::query()->find(self::TENANT_A);
        if (! $tenant) {
            $tenant = new Tenant;
            $tenant->id = self::TENANT_A;
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
                'database_name' => self::DB_A,
                'provisioning_status' => 'active',
            ]
        );

        $connection = 'tenant_db_'.$tenant->id;
        Config::set('database.connections.'.$connection, array_merge(
            config('database.connections.tenant'),
            ['database' => self::DB_A]
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

    private function requirePhysicalDatabase(string $databaseName): void
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

    private function registerPhysicalConnection(string $connectionName, string $databaseName): void
    {
        Config::set('database.connections.'.$connectionName, array_merge(
            config('database.connections.tenant'),
            ['database' => $databaseName]
        ));
    }
}
