<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Identity\Models\Membership;
use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3C: Tenant member management tests.
 */
class TenantMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $memberUser;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMemberRbac();
        $this->owner = User::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);

        $this->memberUser = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Member User',
            'email' => 'member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->memberUser, $this->tenant, 'member', 'active');
    }

    protected function seedMemberRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach (['member.view', 'member.assign-role', 'member.suspend', 'dashboard.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], ['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignMemberRole(User $user, Tenant $tenant, string $roleName, array $permissions): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => $roleName, 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach ($permissions as $perm) {
            $p = Permission::findByName($perm, config('auth.defaults.guard'));
            if ($p && ! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_member_list_requires_member_view(): void
    {
        $this->assignMemberRole($this->owner, $this->tenant, 'tenant-admin', ['member.view', 'dashboard.view']);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/members');
        $response->assertStatus(200);
    }

    public function test_member_list_includes_name_when_home_tenant_differs(): void
    {
        $otherOwner = User::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherOwner);

        $crossTenantMember = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'name' => 'Cross Tenant Member',
            'email' => 'cross-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->createMembership($crossTenantMember, $this->tenant, 'member', 'active');

        $this->assignMemberRole($this->owner, $this->tenant, 'tenant-admin', ['member.view', 'dashboard.view']);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/members');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Members/Index')
            ->has('members', 3)
            ->where('members', function ($members) use ($crossTenantMember) {
                return collect($members)->contains(
                    fn (array $row) => ($row['user']['name'] ?? null) === 'Cross Tenant Member'
                        && ($row['user']['email'] ?? null) === $crossTenantMember->email
                );
            }));
    }

    public function test_member_list_without_permission_returns_403(): void
    {
        $this->assignMemberRole($this->memberUser, $this->tenant, 'member', ['dashboard.view']);

        $response = $this->actingAsTenantUser($this->memberUser, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/members');
        $response->assertStatus(403);
    }

    public function test_cannot_suspend_last_owner(): void
    {
        $this->assignMemberRole($this->owner, $this->tenant, 'tenant-admin', ['member.suspend', 'member.assign-role', 'member.view', 'dashboard.view']);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/'.$this->owner->id.'/suspend');
        $response->assertSessionHasErrors('status');
    }

    public function test_owner_can_suspend_member(): void
    {
        $this->assignMemberRole($this->owner, $this->tenant, 'tenant-admin', ['member.suspend', 'member.view', 'dashboard.view']);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/'.$this->memberUser->id.'/suspend');
        $response->assertSessionHasNoErrors();

        tenancy()->initialize($this->tenant);
        $tu = Membership::where('tenant_id', $this->tenant->id)->where('user_id', $this->memberUser->id)->first();
        $this->assertEquals('suspended', $tu->status);
        tenancy()->end();
    }
}
