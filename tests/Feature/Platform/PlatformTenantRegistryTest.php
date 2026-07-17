<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantHandleValidator;
use Tests\TestCase;

class PlatformTenantRegistryTest extends TestCase
{
    protected function platformUserWithPermissions(array $permissions): PlatformUser
    {
        $user = PlatformUser::create([
            'name' => 'Registry Operator',
            'email' => 'registry-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $names = array_values(array_unique(array_merge(['platform.access'], $permissions)));

        $role = PlatformRole::firstOrCreate([
            'name' => 'registry-test-'.uniqid(),
            'guard_name' => 'platform',
        ]);

        foreach ($names as $name) {
            $permission = PlatformPermission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'platform',
            ]);
            DB::connection('central')->table('platform_role_has_permissions')->insertOrIgnore([
                'platform_role_id' => $role->id,
                'platform_permission_id' => $permission->id,
            ]);
        }

        DB::connection('central')->table('platform_model_has_roles')->insertOrIgnore([
            'platform_role_id' => $role->id,
            'model_type' => PlatformUser::class,
            'model_id' => $user->id,
        ]);

        return $user;
    }

    public function test_handle_validator_accepts_valid_and_normalizes_case(): void
    {
        $validator = app(TenantHandleValidator::class);
        $result = $validator->evaluate('EngYh', checkAvailability: true);
        $this->assertSame(TenantHandleValidator::CODE_AVAILABLE, $result['code']);
        $this->assertSame('engyh', $result['handle']);
    }

    public function test_handle_validator_rejects_invalid_reserved_duplicate_and_fqdn(): void
    {
        $validator = app(TenantHandleValidator::class);

        $this->assertSame(TenantHandleValidator::CODE_INVALID, $validator->evaluate('ab', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_INVALID, $validator->evaluate('-engyh', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_INVALID, $validator->evaluate('engyh-', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_INVALID, $validator->evaluate('Bad_Chars', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_INVALID, $validator->evaluate('portal.customer.com', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_RESERVED, $validator->evaluate('platform', checkAvailability: false)['code']);
        $this->assertSame(TenantHandleValidator::CODE_RESERVED, $validator->evaluate('login', checkAvailability: false)['code']);

        Tenant::factory()->create(['slug' => 'taken-handle']);
        $this->assertSame(TenantHandleValidator::CODE_TAKEN, $validator->evaluate('Taken-Handle', checkAvailability: true)['code']);
        $this->assertSame(TenantHandleValidator::CODE_TAKEN, $validator->evaluate('TAKEN-HANDLE', checkAvailability: true)['code']);
    }

    public function test_soft_deleted_handle_is_not_reusable(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'retired-handle']);
        $tenant->delete();

        $result = app(TenantHandleValidator::class)->evaluate('retired-handle', checkAvailability: true);
        $this->assertSame(TenantHandleValidator::CODE_TAKEN, $result['code']);
    }

    public function test_list_is_central_only_and_excludes_application_owner(): void
    {
        $user = $this->platformUserWithPermissions(['platform.tenants.view']);
        Tenant::factory()->create(['name' => 'Alpha Org', 'slug' => 'alpha-org']);

        $this->actingAs($user, 'platform')
            ->get(route('platform.tenants.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Index')
                ->has('tenants.data')
                ->where('tenants.data.0.handle', 'alpha-org')
                ->missing('tenants.data.0.application_owner')
                ->has('tenants.data.0.lifecycle_status')
                ->has('tenants.data.0.provisioning_status'));

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_detail_loads_application_owner_and_closes_tenancy(): void
    {
        $createUser = $this->platformUserWithPermissions(['platform.tenants.create', 'platform.tenants.view']);
        $handle = 'owner-detail-'.substr(uniqid(), -6);
        $ownerEmail = 'owner-'.$handle.'@example.com';

        $this->actingAs($createUser, 'platform')->post(route('platform.tenants.store'), [
            'organization_name' => 'Owner Detail Org',
            'handle' => $handle,
            'owner_name' => 'Detail Owner',
            'owner_email' => $ownerEmail,
            'owner_password' => 'password-Str0ng!',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('slug', $handle)->firstOrFail();

        $this->actingAs($createUser, 'platform')
            ->get(route('platform.tenants.show', $tenant))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Tenants/Show')
                ->where('tenant.handle', $handle)
                ->where('tenant.entry_url', app(\App\Http\Auth\TenantEntryUrlResolver::class)->entryUrl(
                    \Modules\Tenancy\Models\Tenant::query()->where('slug', $handle)->firstOrFail()
                ))
                ->where('tenant.application_owner.email', $ownerEmail)
                ->where('tenant.commercial_owner_contact.assigned', false)
                ->has('tenant.lifecycle_status')
                ->has('tenant.provisioning_status'));

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_create_requires_handle_and_rejects_isolation_override(): void
    {
        $user = $this->platformUserWithPermissions(['platform.tenants.create']);

        $this->actingAs($user, 'platform')
            ->from(route('platform.tenants.create'))
            ->post(route('platform.tenants.store'), [
                'organization_name' => 'No Handle Org',
                'owner_name' => 'Owner',
                'owner_email' => 'no-handle-'.uniqid().'@example.com',
                'owner_password' => 'password-Str0ng!',
            ])
            ->assertRedirect(route('platform.tenants.create'))
            ->assertSessionHasErrors('handle');

        $rules = (new \Modules\Tenancy\Http\Requests\PlatformCreateTenantRequest)->rules();
        $validator = \Illuminate\Support\Facades\Validator::make([
            'organization_name' => 'Iso Override Org',
            'handle' => 'iso-override-abc',
            'owner_name' => 'Owner',
            'owner_email' => 'iso-valid@example.com',
            'owner_password' => 'password-Str0ng!',
            'isolation_level' => 'database',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('isolation_level', $validator->errors()->toArray());
    }

    public function test_create_uses_default_isolation_and_dual_axes_completed(): void
    {
        config(['tenancy_storage.default_isolation_level' => 'shared']);
        $user = $this->platformUserWithPermissions(['platform.tenants.create', 'platform.tenants.view']);
        $handle = 'create-ok-'.substr(uniqid(), -6);

        $this->actingAs($user, 'platform')->post(route('platform.tenants.store'), [
            'organization_name' => 'Create OK Org',
            'handle' => $handle,
            'owner_name' => 'Owner',
            'owner_email' => 'create-ok-'.uniqid().'@example.com',
            'owner_password' => 'password-Str0ng!',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('slug', $handle)->firstOrFail();
        $this->assertSame('shared', $tenant->isolation_level);

        $this->actingAs($user, 'platform')
            ->get(route('platform.tenants.show', $tenant))
            ->assertInertia(fn ($page) => $page
                ->where('tenant.lifecycle_status', 'active')
                ->where('tenant.provisioning_status', 'completed')
                ->where('tenant.handle', $handle));
    }

    public function test_database_pending_shows_action_required_separately_from_lifecycle(): void
    {
        config([
            'tenancy_storage.mode' => 'database_per_tenant',
            'tenancy_storage.default_isolation_level' => 'database',
            'tenancy_storage.db_creation_mode' => 'manual',
            'tenancy_storage.allow_database_per_tenant' => true,
        ]);

        $user = $this->platformUserWithPermissions(['platform.tenants.create', 'platform.tenants.view']);
        $handle = 'db-pend-'.substr(uniqid(), -6);

        $this->actingAs($user, 'platform')->post(route('platform.tenants.store'), [
            'organization_name' => 'Pending DB Org',
            'handle' => $handle,
            'owner_name' => 'Owner',
            'owner_email' => 'pend-'.uniqid().'@example.com',
            'owner_password' => 'password-Str0ng!',
        ])->assertRedirect();

        $tenant = Tenant::query()->where('slug', $handle)->firstOrFail();
        $this->assertDatabaseHas('tenant_database_config', [
            'tenant_id' => $tenant->id,
            'provisioning_status' => 'pending',
        ], 'central');

        $this->actingAs($user, 'platform')
            ->get(route('platform.tenants.show', $tenant))
            ->assertInertia(fn ($page) => $page
                ->where('tenant.lifecycle_status', 'active')
                ->where('tenant.provisioning_status', 'action_required'));
    }

    public function test_update_name_does_not_change_handle(): void
    {
        $user = $this->platformUserWithPermissions([
            'platform.tenants.create',
            'platform.tenants.view',
            'platform.tenants.update',
        ]);
        $handle = 'edit-name-'.substr(uniqid(), -6);

        $this->actingAs($user, 'platform')->post(route('platform.tenants.store'), [
            'organization_name' => 'Edit Name Org',
            'handle' => $handle,
            'owner_name' => 'Owner',
            'owner_email' => 'edit-'.uniqid().'@example.com',
            'owner_password' => 'password-Str0ng!',
        ]);

        $tenant = Tenant::query()->where('slug', $handle)->firstOrFail();

        $this->actingAs($user, 'platform')->patch(route('platform.tenants.update', $tenant), [
            'name' => 'Renamed Org',
        ])->assertRedirect(route('platform.tenants.show', $tenant));

        $tenant->refresh();
        $this->assertSame('Renamed Org', $tenant->name);
        $this->assertSame($handle, $tenant->slug);

        $rules = (new \Modules\Tenancy\Http\Requests\PlatformUpdateTenantRequest)->rules();
        $validator = \Illuminate\Support\Facades\Validator::make([
            'name' => 'Renamed Org Again',
            'handle' => 'should-not-apply',
        ], $rules);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('handle', $validator->errors()->toArray());
    }

    public function test_availability_endpoint_is_platform_auth_post_and_non_pii(): void
    {
        $this->postJson(route('platform.tenants.handle-availability'), ['handle' => 'anyhandle'])
            ->assertStatus(401);

        $user = $this->platformUserWithPermissions(['platform.tenants.create']);
        Tenant::factory()->create(['name' => 'Secret Org Name', 'slug' => 'secret-org']);

        $response = $this->actingAs($user, 'platform')
            ->postJson(route('platform.tenants.handle-availability'), ['handle' => 'secret-org']);

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('taken', $data['code']);
        $this->assertSame('secret-org', $data['handle']);
        $this->assertArrayNotHasKey('name', $data);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertStringNotContainsString('Secret Org Name', json_encode($data));
    }

    public function test_permission_matrix_view_create_update(): void
    {
        $viewer = $this->platformUserWithPermissions(['platform.tenants.view']);
        $creator = $this->platformUserWithPermissions(['platform.tenants.create']);
        $updater = $this->platformUserWithPermissions(['platform.tenants.update']);
        $tenant = Tenant::factory()->create(['slug' => 'perm-tenant']);

        $this->actingAs($viewer, 'platform')
            ->post(route('platform.tenants.store'), [
                'organization_name' => 'X',
                'handle' => 'perm-create-'.substr(uniqid(), -5),
                'owner_name' => 'O',
                'owner_email' => 'v-'.uniqid().'@example.com',
                'owner_password' => 'password-Str0ng!',
            ])->assertForbidden();

        $this->actingAs($viewer, 'platform')
            ->patch(route('platform.tenants.update', $tenant), ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($creator, 'platform')
            ->patch(route('platform.tenants.update', $tenant), ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($updater, 'platform')
            ->post(route('platform.tenants.store'), [
                'organization_name' => 'X',
                'handle' => 'perm-upd-'.substr(uniqid(), -5),
                'owner_name' => 'O',
                'owner_email' => 'u-'.uniqid().'@example.com',
                'owner_password' => 'password-Str0ng!',
            ])->assertForbidden();
    }

    public function test_no_retry_completion_http_route_exists(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            $this->assertFalse(
                str_starts_with($uri, 'platform') && str_contains($uri, 'provision-storage'),
                'Unexpected platform provision-storage route: '.$uri
            );
            $this->assertFalse(
                str_starts_with($uri, 'platform') && str_contains($uri, 'retry'),
                'Unexpected platform retry route: '.$uri
            );
        }
    }

    public function test_path_fallback_resolves_selected_handle(): void
    {
        $user = $this->platformUserWithPermissions(['platform.tenants.create']);
        $handle = 'path-res-'.substr(uniqid(), -6);
        $email = 'path-'.$handle.'@example.com';

        $this->actingAs($user, 'platform')->post(route('platform.tenants.store'), [
            'organization_name' => 'Path Resolve Org',
            'handle' => $handle,
            'owner_name' => 'Owner',
            'owner_email' => $email,
            'owner_password' => 'password-Str0ng!',
        ]);

        // Clear platform session so tenant web login is not interrupted
        auth('platform')->logout();
        $this->flushSession();

        $this->post('/t/'.$handle.'/login', [
            'email' => $email,
            'password' => 'password-Str0ng!',
        ])->assertRedirect($this->tenantDashboardRedirectUri(Tenant::query()->where('slug', $handle)->firstOrFail()));
    }

    public function test_self_registration_unchanged_still_auto_generates_handle(): void
    {
        $before = Tenant::count();
        $this->registerTenantUser('Self Reg User', 'self-reg-'.uniqid().'@example.com');
        $this->assertSame($before + 1, Tenant::count());
        $tenant = Tenant::query()->latest('created_at')->first();
        $this->assertNotNull($tenant);
        $this->assertTrue(str_starts_with((string) $tenant->slug, 'ws-'));
    }
}
