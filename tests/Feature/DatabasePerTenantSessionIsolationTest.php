<?php

namespace Tests\Feature;

use Modules\Identity\Models\TenantUser;
use App\Support\Tenancy\TenantLayerMigrationRunner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Tests\TestCase;

/** T-S5B-08 / T-S5B-08b — physical database session isolation attestation. */
class DatabasePerTenantSessionIsolationTest extends TestCase
{
    private const DB_A = 'jabal_tenant_dedicated_a_testing';
    private const DB_B = 'jabal_tenant_dedicated_b_testing';
    private const CONN_A = 'dedicated_tenant_a';
    private const CONN_B = 'dedicated_tenant_b';
    private const TENANT_A = '11111111-1111-1111-1111-111111111111';
    private const TENANT_B = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        config(['tenancy_storage.mode' => 'database_per_tenant']);
        $this->requirePhysicalDatabase(self::DB_A);
        $this->requirePhysicalDatabase(self::DB_B);
        app(TenantLayerMigrationRunner::class)->ensureMigrated(self::DB_A);
        app(TenantLayerMigrationRunner::class)->ensureMigrated(self::DB_B);
        $this->registerPhysicalConnection(self::CONN_A, self::DB_A);
        $this->registerPhysicalConnection(self::CONN_B, self::DB_B);
        $this->truncateSessions(self::CONN_A);
        $this->truncateSessions(self::CONN_B);
        $this->forgetResolvedSession();
    }

    /** T-S5B-08: tenant A session row is written only to dedicated DB A. */
    public function test_t_s5b_08_tenant_a_login_writes_only_to_physical_db_a(): void
    {
        [$tenantA, $userA] = $this->createDedicatedTenantFixture(self::TENANT_A, 'Tenant A Org', 'user-a-'.uniqid().'@example.com', self::DB_A);
        $this->assignDashboardViewToUser($userA, $tenantA);
        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->loginAs($userA->email, 'password')->assertRedirect($this->tenantDashboardRedirectUri($tenantA));
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('tenant_db_'.$tenantA->id, config('session.connection'));
        $this->assertSame(1, $this->sessionCount(self::CONN_A));
        $this->assertSame(0, $this->sessionCount(self::CONN_B));
    }

    /** T-S5B-08: tenant B session row is written only to dedicated DB B. */
    public function test_t_s5b_08_tenant_b_login_writes_only_to_physical_db_b(): void
    {
        [$tenantB, $userB] = $this->createDedicatedTenantFixture(self::TENANT_B, 'Tenant B Org', 'user-b-'.uniqid().'@example.com', self::DB_B);
        $this->assignDashboardViewToUser($userB, $tenantB);
        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->loginAs($userB->email, 'password')->assertRedirect($this->tenantDashboardRedirectUri($tenantB));
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('tenant_db_'.$tenantB->id, config('session.connection'));
        $this->assertSame(0, $this->sessionCount(self::CONN_A));
        $this->assertSame(1, $this->sessionCount(self::CONN_B));
    }

    /** T-S5B-08b: tenant A dashboard GET preserves dedicated session connection. */
    public function test_t_s5b_08b_tenant_a_dashboard_preserves_dedicated_session_connection(): void
    {
        [$tenantA, $userA] = $this->createDedicatedTenantFixture(self::TENANT_A, 'Tenant A Org', 'user-a-dash-'.uniqid().'@example.com', self::DB_A);
        $connectionA = 'tenant_db_'.$tenantA->id;
        $this->assignDashboardViewToUser($userA, $tenantA);
        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $loginA = $this->loginAs($userA->email, 'password');
        $loginA->assertRedirect($this->tenantDashboardRedirectUri($tenantA));
        $this->getWithCookies('/t/'.$tenantA->entryKey().'/dashboard', $loginA)->assertOk();
        $this->assertSame('database', config('session.driver'));
        $this->assertSame($connectionA, config('session.connection'));
        $this->assertSame(1, $this->sessionCount(self::CONN_A));
    }

    /** T-S5B-08b: tenant B dashboard GET preserves dedicated session connection. */
    public function test_t_s5b_08b_tenant_b_dashboard_preserves_dedicated_session_connection(): void
    {
        [$tenantB, $userB] = $this->createDedicatedTenantFixture(self::TENANT_B, 'Tenant B Org', 'user-b-dash-'.uniqid().'@example.com', self::DB_B);
        $connectionB = 'tenant_db_'.$tenantB->id;
        $this->assignDashboardViewToUser($userB, $tenantB);
        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $loginB = $this->loginAs($userB->email, 'password');
        $loginB->assertRedirect($this->tenantDashboardRedirectUri($tenantB));
        $this->getWithCookies('/t/'.$tenantB->entryKey().'/dashboard', $loginB)->assertOk();
        $this->assertSame('database', config('session.driver'));
        $this->assertSame($connectionB, config('session.connection'));
        $this->assertSame(1, $this->sessionCount(self::CONN_B));
    }

    private function loginAs(string $email, string $password): TestResponse
    {
        $user = \Modules\Identity\Models\TenantUser::findForLogin($email);
        $this->assertNotNull($user);
        $tenant = \Modules\Tenancy\Models\Tenant::query()->find($user->tenant_id);
        $this->assertNotNull($tenant);

        // Prefer UUID machine path so session deferral attestation stays UUID-keyed.
        return $this->call(
            'POST',
            '/t/'.$tenant->id.'/login',
            ['email' => $email, 'password' => $password],
            []
        );
    }

    private function getWithCookies(string $uri, TestResponse $fromResponse): TestResponse
    {
        return $this->call('GET', $uri, [], $this->cookiesFromResponse($fromResponse));
    }

    private function cookiesFromResponse(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    private function createDedicatedTenantFixture(string $tenantId, string $name, string $email, string $databaseName): array
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            $tenant = new Tenant;
            $tenant->id = $tenantId;
            $tenant->forceFill(['name' => $name, 'slug' => 'ded-'.substr($tenantId, 0, 8), 'isolation_level' => 'database', 'status' => 'active'])->save();
        } else {
            $tenant->update(['name' => $name, 'isolation_level' => 'database', 'status' => 'active']);
        }
        TenantDatabaseConfig::query()->updateOrCreate(['tenant_id' => $tenant->id], ['isolation_level' => 'database', 'database_name' => $databaseName, 'provisioning_status' => 'active']);
        Config::set('database.connections.tenant_db_'.$tenant->id, array_merge(config('database.connections.tenant'), ['database' => $databaseName]));
        tenancy()->initialize($tenant);
        try {
            $provisioner = app(TenantRbacProvisioner::class);
            $provisioner->ensureGlobalPermissions();
            $provisioner->ensureRolesForTenant($tenant);
            $user = TenantUser::create(['tenant_id' => $tenant->id, 'name' => $name.' User', 'email' => $email, 'password' => 'password']);
            Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'membership_type' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            $provisioner->assignTenantAdminRole($user, $tenant);
            $resolvedUser = TenantUser::withoutGlobalScope('tenant')->findOrFail($user->id);
        } finally {
            tenancy()->end();
        }

        return [$tenant->fresh(['databaseConfig']), $resolvedUser];
    }

    private function requirePhysicalDatabase(string $databaseName): void
    {
        if (! str_ends_with($databaseName, '_testing')) {
            throw new \RuntimeException('Refusing to use non-testing database: '.$databaseName);
        }
        $exists = DB::connection('central')->selectOne('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);
        if (! $exists) {
            $this->markTestSkipped('Dedicated test database ['.$databaseName.'] is missing. Run: php tests/Support/ensure_dedicated_test_databases.php');
        }
    }

    private function registerPhysicalConnection(string $connectionName, string $databaseName): void
    {
        Config::set('database.connections.'.$connectionName, array_merge(config('database.connections.tenant'), ['database' => $databaseName]));
        if (! Schema::connection($connectionName)->hasTable('sessions')) {
            Schema::connection($connectionName)->create('sessions', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('id')->primary();
                $table->uuid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    private function truncateSessions(string $connectionName): void
    {
        if (Schema::connection($connectionName)->hasTable('sessions')) {
            DB::connection($connectionName)->table('sessions')->truncate();
        }
    }

    private function sessionCount(string $connectionName): int
    {
        return (int) DB::connection($connectionName)->table('sessions')->count();
    }

    private function resetHttpClientState(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        Auth::forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        config(['session.connection' => (string) config('tenancy_storage.shared_connection', 'tenant')]);
        $this->forgetResolvedSession();
    }

    private function forgetResolvedSession(): void
    {
        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }
}
