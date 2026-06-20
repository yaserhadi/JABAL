<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureApplicationRuntime;
use App\Http\Middleware\ConfigureTenantSessionConnection;
use App\Http\Middleware\InitializeTenancyByPathWhenApplicable;
use App\Models\User;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DatabasePerTenantSessionConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        config(['tenancy_storage.mode' => 'database_per_tenant']);

        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }

    /** T-S5B-02 */
    public function test_path_request_defers_and_resolves_dynamic_connection_before_session(): void
    {
        $tenant = $this->createDatabasePerTenantFixture();

        $request = Request::create('/t/'.$tenant->id.'/dashboard', 'GET');
        $next = function (Request $req) use ($tenant): Response {
            $this->assertSame('tenant_db_'.$tenant->id, config('session.connection'));

            return response('ok');
        };

        app(ConfigureApplicationRuntime::class)->handle($request, function (Request $req) use ($next) {
            $this->assertTrue($req->attributes->get(ConfigureApplicationRuntime::SESSION_CONNECTION_DEFERRED));

            return app(InitializeTenancyByPathWhenApplicable::class)->handle($req, function (Request $req) use ($next) {
                return app(ConfigureTenantSessionConnection::class)->handle($req, $next);
            });
        });
    }

    /** T-S5B-05 */
    public function test_platform_unaffected_under_db_per_tenant_mode(): void
    {
        $this->createDatabasePerTenantFixture();

        $response = $this->get('/platform/login');

        $response->assertOk();
        $this->assertSame('central', config('session.connection'));
        $this->assertFalse((bool) request()->attributes->get(ConfigureApplicationRuntime::SESSION_CONNECTION_DEFERRED));
    }

    /** T-S5B-03 */
    public function test_authenticated_tenant_request_persists_session_on_dedicated_connection(): void
    {
        $user = $this->registerTenantUser();
        $tenant = $user->personalTenant();
        $this->assertNotNull($tenant);

        $tenant->update(['isolation_level' => 'database']);
        $sharedTestingDatabase = (string) config('database.connections.tenant.database');

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => $sharedTestingDatabase,
            'provisioning_status' => 'active',
        ]);

        Config::set('database.connections.tenant_db_'.$tenant->id, array_merge(
            config('database.connections.tenant'),
            ['database' => $sharedTestingDatabase]
        ));

        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->actingAsTenantUser($user, $tenant)
            ->get('/t/'.$tenant->id.'/dashboard')
            ->assertOk();

        $connection = 'tenant_db_'.$tenant->id;
        $this->assertTrue(Schema::connection($connection)->hasTable('sessions'));
        $this->assertGreaterThan(0, DB::connection($connection)->table('sessions')->count());
    }

    /** T-S5B-04 */
    public function test_two_db_per_tenant_tenants_resolve_distinct_session_connections(): void
    {
        $tenantA = $this->createDatabasePerTenantFixture('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $tenantB = $this->createDatabasePerTenantFixture('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');

        $resolver = app(TenantStorageResolver::class);

        $this->assertSame('tenant_db_'.$tenantA->id, $resolver->connectionFor($tenantA));
        $this->assertSame('tenant_db_'.$tenantB->id, $resolver->connectionFor($tenantB));
        $this->assertNotSame(
            $resolver->connectionFor($tenantA),
            $resolver->connectionFor($tenantB)
        );
    }

    protected function createDatabasePerTenantFixture(?string $id = null): Tenant
    {
        $attributes = [
            'name' => 'Db Per Tenant Org',
            'slug' => 'dbpt-'.uniqid(),
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ];

        if ($id !== null) {
            $attributes['id'] = $id;
        }

        $tenant = Tenant::query()->create($attributes);

        $sharedTestingDatabase = (string) config('database.connections.tenant.database');

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => $sharedTestingDatabase,
            'provisioning_status' => 'active',
        ]);

        $connectionName = 'tenant_db_'.$tenant->id;
        Config::set('database.connections.'.$connectionName, array_merge(
            config('database.connections.tenant'),
            ['database' => $sharedTestingDatabase]
        ));

        return $tenant->fresh(['databaseConfig']);
    }
}
