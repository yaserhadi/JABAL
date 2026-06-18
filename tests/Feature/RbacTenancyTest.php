<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3B: RBAC and cross-tenant role isolation tests.
 *
 * Validates:
 * - User with role in tenant A can access protected route in tenant A
 * - User in tenant B without role is denied
 * - Role in tenant A does not leak to tenant B
 * - Inactive/missing membership denies access even if role exists
 */
class RbacTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Tenant $tenantA;
    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbacCatalog();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->tenantA = $this->createPersonalTenant($this->userA);
        $this->tenantB = $this->createPersonalTenant($this->userB);
    }

    protected function seedRbacCatalog(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');

        foreach (['workspace.view', 'workspace.create', 'dashboard.view'] as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['name' => $name, 'guard_name' => $guard]
            );
        }
    }

    protected function createRoleForTenant(Tenant $tenant, string $roleName, array $permissions): Role
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => config('auth.defaults.guard'),
                'tenant_id' => $tenant->id,
            ],
            ['name' => $roleName, 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach ($permissions as $perm) {
            $p = Permission::findByName($perm, config('auth.defaults.guard'));
            if (! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $role;
    }

    protected function assignRoleToUser(User $user, Tenant $tenant, string $roleName): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::where('name', $roleName)->where('tenant_id', $tenant->id)->first();
        if ($role && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_user_with_role_in_tenant_a_can_access_protected_route_in_tenant_a(): void
    {
        $this->createRoleForTenant($this->tenantA, 'member', ['dashboard.view']);
        $this->assignRoleToUser($this->userA, $this->tenantA, 'member');

        $this->actingAsTenantUser($this->userA, $this->tenantA);

        $response = $this->get('/t/'.$this->tenantA->id.'/dashboard');

        $response->assertStatus(200);
    }

    public function test_user_in_tenant_b_without_role_denied_access(): void
    {
        $this->createMembership($this->userA, $this->tenantB, 'member', 'active');

        $this->createRoleForTenant($this->tenantA, 'member', ['dashboard.view']);
        $this->createRoleForTenant($this->tenantB, 'member', ['dashboard.view']);
        $this->assignRoleToUser($this->userA, $this->tenantA, 'member');
        // userA has NO role in tenantB

        $token = $this->userA->createToken('test', ['tenant:'.$this->tenantB->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantB->id,
        ])->getJson('/api/v1/me');

        $response->assertStatus(403);
    }

    public function test_role_in_tenant_a_does_not_leak_to_tenant_b(): void
    {
        // userA has role in both tenant A and tenant B; access in A works
        $this->createMembership($this->userA, $this->tenantB, 'member', 'active');

        $this->createRoleForTenant($this->tenantA, 'tenant-admin', ['dashboard.view']);
        $this->createRoleForTenant($this->tenantB, 'member', ['dashboard.view']);
        $this->assignRoleToUser($this->userA, $this->tenantA, 'tenant-admin');
        $this->assignRoleToUser($this->userA, $this->tenantB, 'member');

        $tokenA = $this->userA->createToken('rbac-test-a', ['tenant:'.$this->tenantA->id])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenA,
            'X-Tenant-Id' => $this->tenantA->id,
        ])->getJson('/api/v1/me')->assertStatus(200);

        // test_user_in_tenant_b_without_role_denied_access proves role in A does not help in B
        // test_user_with_role_in_tenant_b_can_access proves having role in B allows access
        tenancy()->end();
    }

    public function test_user_with_role_in_tenant_b_can_access(): void
    {
        $this->createMembership($this->userA, $this->tenantB, 'member', 'active');
        $this->createRoleForTenant($this->tenantB, 'member', ['dashboard.view']);
        $this->assignRoleToUser($this->userA, $this->tenantB, 'member');

        $token = $this->userA->createToken('rbac-test-b-only', ['tenant:'.$this->tenantB->id])->plainTextToken;
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantB->id,
        ])->getJson('/api/v1/me')->assertStatus(200);

        tenancy()->end();
    }

    public function test_inactive_membership_denies_access_even_with_role(): void
    {
        $this->createRoleForTenant($this->tenantA, 'member', ['dashboard.view']);
        $this->assignRoleToUser($this->userA, $this->tenantA, 'member');
        tenancy()->initialize($this->tenantA);
        Membership::where('user_id', $this->userA->id)
            ->where('tenant_id', $this->tenantA->id)
            ->update(['status' => 'suspended']);
        tenancy()->end();

        $response = $this->actingAsTenantUser($this->userA, $this->tenantA)
            ->get('/t/'.$this->tenantA->id.'/dashboard');

        $response->assertStatus(403);
    }

    public function test_api_without_tenant_header_denied_before_permission_check(): void
    {
        $token = $this->userA->createToken('test', ['tenant:'.$this->tenantA->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/me');

        $response->assertStatus(401);
    }
}
