<?php

namespace Tests;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Identity\Services\TenantRegistrationService;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * PHASE 2: TestCase uses Stancl tenancy() for tenant context.
 * PHASE 3B: RBAC helpers for tests that hit permission-protected routes.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Defense in depth: refuse to run tests against non-isolated databases.
     * RefreshDatabase performs migrate:fresh which would wipe dev data otherwise.
     * Test DBs must be explicitly named with a "_testing" suffix.
     *
     * Read directly from env so the guard fires BEFORE the framework boots and
     * BEFORE RefreshDatabase's migrate:fresh can touch any connection.
     */
    protected function setUp(): void
    {
        $central = (string) env('DB_DATABASE_CENTRAL', '');
        $tenant = (string) env('DB_DATABASE_TENANT', '');

        if (! str_ends_with($central, '_testing') || ! str_ends_with($tenant, '_testing')) {
            throw new \RuntimeException(
                'Refusing to run tests against non-isolated databases. '.
                'Expected DB names ending in "_testing", got central='.$central.', tenant='.$tenant.'. '.
                'Set DB_DATABASE_CENTRAL and DB_DATABASE_TENANT in phpunit.xml.'
            );
        }

        parent::setUp();

        $this->withoutVite();
    }

    protected function afterRefreshingDatabase()
    {
        $this->artisan('migrate:fresh', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        $this->seed(\Database\Seeders\PlatformRbacSeeder::class);
        $this->seed(\Database\Seeders\TenantContactRoleSeeder::class);
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);
        $this->seed(\Database\Seeders\BillingCatalogSeeder::class);
    }

    protected function grantPlatformAccess(\App\Models\PlatformUser $user): void
    {
        $roleId = \App\Models\PlatformRole::query()
            ->where('name', 'platform-super-admin')
            ->value('id');

        if (! $roleId) {
            $this->seed(\Database\Seeders\PlatformRbacSeeder::class);
            $roleId = \App\Models\PlatformRole::query()
                ->where('name', 'platform-super-admin')
                ->value('id');
        }

        if ($roleId) {
            \Illuminate\Support\Facades\DB::connection('central')->table('platform_model_has_roles')->insertOrIgnore([
                'platform_role_id' => $roleId,
                'model_type' => \App\Models\PlatformUser::class,
                'model_id' => $user->id,
            ]);
        }
    }

    protected function createMembership(
        \App\Models\User $user,
        \Modules\Tenancy\Models\Tenant $tenant,
        string $membershipType = 'member',
        string $status = 'active'
    ): \Modules\Identity\Models\Membership {
        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return \Modules\Identity\Models\Membership::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ],
                [
                    'membership_type' => $membershipType,
                    'status' => $status,
                    'joined_at' => $status === 'active' ? now() : null,
                ]
            );
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * Set the currently authenticated user to act as a specific tenant.
     * Uses Stancl tenancy() for context management.
     *
     * @return $this
     */
    protected function actingAsTenant(Tenant $tenant): static
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenant);

        return $this;
    }

    /**
     * Web/session tests: initialize tenancy before actingAs to avoid scoped User/RBAC query errors.
     */
    protected function actingAsTenantUser(User $user, Tenant $tenant, string $guard = 'web'): static
    {
        $this->actingAsTenant($tenant);

        return $this->actingAs($user, $guard);
    }

    /**
     * Create a personal tenant for the given user.
     *
     * PHASE 2: Sets status = 'active'.
     *
     * @param  mixed  $user
     */
    protected function createPersonalTenant($user): Tenant
    {
        $tenant = $user->personalTenant();

        if (! $tenant) {
            $tenant = Tenant::query()->where('created_by', $user->id)->first();
        }

        if ($tenant) {
            $provisioner = app(\Modules\Tenancy\Services\TenantRbacProvisioner::class);
            $provisioner->ensureGlobalPermissions();
            $provisioner->ensureRolesForTenant($tenant);
            $provisioner->assignTenantAdminRole($user, $tenant);
        }

        return $tenant;
    }

    protected function registerTenantUser(string $name = 'Test User', ?string $email = null): User
    {
        $email ??= 'tenant-'.uniqid().'@example.com';

        return app(TenantRegistrationService::class)->registerTenantUser(
            $name,
            $email,
            'password'
        );
    }

    /**
     * Assign dashboard.view to user in tenant (for /me and dashboard routes).
     * PHASE 3B: /api/v1/me and /t/{tenant}/dashboard require permission:dashboard.view.
     */
    protected function assignDashboardViewToUser(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => $guard], ['name' => 'dashboard.view', 'guard_name' => $guard]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        $role->givePermissionTo('dashboard.view');
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * BK-073 dual-profile CI: rewrite Path-style /t/{key}/… (and central /platform) URIs
     * onto Host-bound absolute URLs when TENANCY_ADDRESSING_PROFILE=host.
     *
     * Production Host mode does not register Path URIs; this adapter keeps the existing
     * Path-oriented suite exercisable under a Host-profile boot without duplicating routes.
     *
     * Absolute URIs that already target a non-local Host (Host-matrix tests) are left unchanged.
     *
     * @param  array<string, mixed>  $server
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function rewriteUriForAddressingProfile(string $uri, array $server): array
    {
        if (! isset($this->app) || ! $this->app->bound(\App\Support\Tenancy\TenantAddressingProfile::class)) {
            return [$uri, $server];
        }

        $profile = $this->app->make(\App\Support\Tenancy\TenantAddressingProfile::class);
        if (! $profile->isHost()) {
            return [$uri, $server];
        }

        $parts = parse_url($uri);
        $hostFromUri = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($hostFromUri !== '' && ! in_array($hostFromUri, ['localhost', '127.0.0.1'], true)) {
            return [$uri, $server];
        }

        $path = $parts['path'] ?? (str_starts_with($uri, '/') ? explode('?', $uri, 2)[0] : '/'.$uri);
        $query = isset($parts['query']) ? '?'.$parts['query'] : (str_contains($uri, '?') && ! isset($parts['host'])
            ? '?'.explode('?', $uri, 2)[1]
            : '');

        if (preg_match('#^/t/([^/]+)(/.*)?$#', $path, $m) === 1) {
            $key = $m[1];
            $rest = $m[2] ?? '';
            $tenant = \Illuminate\Support\Str::isUuid($key)
                ? Tenant::query()->find($key)
                : Tenant::query()->where('slug', $key)->first();

            if (! $tenant) {
                return [$uri, $server];
            }

            app(\Modules\Tenancy\Services\TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
            $host = $profile->tenantHostFqdn($tenant->entryKey());
            $absolute = $profile->absoluteOriginForHost($host).($rest === '' ? '/' : $rest).$query;
            $server['HTTP_HOST'] = $host;
            $server['SERVER_NAME'] = $host;

            return [$absolute, $server];
        }

        $platformHost = $profile->platformHost();
        if ($platformHost !== '' && (
            str_starts_with($path, '/platform')
            || in_array($path, ['/login', '/register', '/'], true)
            || str_starts_with($path, '/password')
        )) {
            $absolute = $profile->absoluteOriginForHost($platformHost).$path.$query;
            $server['HTTP_HOST'] = $platformHost;
            $server['SERVER_NAME'] = $platformHost;

            return [$absolute, $server];
        }

        $authHost = $profile->authHost();
        if ($authHost !== '' && str_starts_with($path, '/auth/sso/callback')) {
            $absolute = $profile->absoluteOriginForHost($authHost).$path.$query;
            $server['HTTP_HOST'] = $authHost;
            $server['SERVER_NAME'] = $authHost;

            return [$absolute, $server];
        }

        return [$uri, $server];
    }

    /**
     * Expected tenant dashboard redirect target for the active addressing profile.
     */
    protected function tenantDashboardRedirectUri(Tenant $tenant): string
    {
        return app(\App\Http\Auth\TenantEntryUrlResolver::class)->dashboardUrl($tenant);
    }

    /**
     * Expected tenant-local login URL (optional email query) for the active addressing profile.
     */
    protected function tenantLoginRedirectUri(Tenant $tenant, ?string $email = null): string
    {
        $url = app(\App\Http\Auth\TenantEntryUrlResolver::class)->loginUrl($tenant);
        if (is_string($email) && $email !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'email='.urlencode($email);
        }

        return $url;
    }

    /**
     * Profile-aware absolute URL for an arbitrary named Tenant route.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function tenantNamedRouteUrl(string $name, Tenant $tenant, array $parameters = []): string
    {
        return app(\App\Http\Auth\TenantEntryUrlResolver::class)
            ->namedRouteUrl($name, $tenant, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        [$uri, $server] = $this->rewriteUriForAddressingProfile((string) $uri, $server);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Assert that a model is properly scoped to a tenant.
     *
     * @param  mixed  $model
     * @param  mixed  $tenant
     * @return void
     */
    protected function assertTenantScoped($model, $tenant = null)
    {
        $tenant = $tenant ?? tenancy()->tenant;
        $this->assertNotNull($tenant);
        $this->assertEquals($tenant->id, $model->tenant_id ?? $model->getAttribute('tenant_id'));
    }

    /**
     * Cleanup tenancy context after each test.
     */
    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }
}
