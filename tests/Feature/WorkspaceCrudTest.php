<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Modules\Workspaces\Models\Workspace;
use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3C: Workspace CRUD and RBAC tests.
 */
class WorkspaceCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Tenant $tenantA;
    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
        ]);
        $this->seedWorkspaceRbac();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        $this->tenantA = $this->createPersonalTenant($this->userA);
        $this->tenantB = $this->createPersonalTenant($this->userB);
    }

    protected function seedWorkspaceRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach (['workspace.view', 'workspace.create', 'workspace.update', 'workspace.delete', 'dashboard.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], ['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignWorkspaceRole(User $user, Tenant $tenant, string $roleName = 'tenant-admin'): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => $roleName, 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach (['workspace.view', 'workspace.create', 'workspace.update', 'workspace.delete', 'dashboard.view'] as $perm) {
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

    public function test_workspace_index_returns_list(): void
    {
        $this->assignWorkspaceRole($this->userA, $this->tenantA);
        tenancy()->initialize($this->tenantA);
        Workspace::create(['name' => 'Test', 'slug' => 'test']);
        tenancy()->end();

        $this->actingAs($this->userA);
        tenancy()->initialize($this->tenantA);
        $response = $this->get('/t/'.$this->tenantA->id.'/workspaces');

        $response->assertStatus(200);
        $this->assertStringContainsString('Test', $response->getContent());
    }

    public function test_workspace_store_creates_workspace(): void
    {
        $this->assignWorkspaceRole($this->userA, $this->tenantA);
        $this->actingAs($this->userA);
        tenancy()->initialize($this->tenantA);

        $response = $this->post('/t/'.$this->tenantA->id.'/workspaces', [
            'name' => 'New Workspace',
            'slug' => 'new-workspace',
        ]);

        $response->assertRedirect();
        tenancy()->initialize($this->tenantA);
        $this->assertNotNull(Workspace::where('slug', 'new-workspace')->first());
    }

    public function test_workspace_binding_isolation_returns_404_for_cross_tenant(): void
    {
        $this->assignWorkspaceRole($this->userA, $this->tenantA);
        tenancy()->initialize($this->tenantB);
        $workspaceB = Workspace::create(['name' => 'B Workspace', 'slug' => 'b-workspace']);
        tenancy()->end();

        $this->actingAs($this->userA);
        tenancy()->initialize($this->tenantA);

        $response = $this->get('/t/'.$this->tenantA->id.'/workspaces/'.$workspaceB->id);

        $response->assertStatus(404);
    }

    public function test_workspace_without_permission_returns_403(): void
    {
        TenantUser::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userB->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        // Catalog `member` includes workspace.view; use a dedicated role for "no workspace" coverage.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->getTenantKey());
        $guard = config('auth.defaults.guard');
        $role = Role::firstOrCreate(
            ['name' => 'dashboard-only', 'guard_name' => $guard, 'tenant_id' => $this->tenantA->id],
            ['name' => 'dashboard-only', 'guard_name' => $guard, 'tenant_id' => $this->tenantA->id]
        );
        $role->syncPermissions([Permission::findByName('dashboard.view', $guard)]);
        $this->userB->syncRoles([$role]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($this->userB);
        tenancy()->initialize($this->tenantA);
        $response = $this->get('/t/'.$this->tenantA->id.'/workspaces');

        $response->assertStatus(403);
    }

    public function test_workspace_api_crud(): void
    {
        $this->assignWorkspaceRole($this->userA, $this->tenantA);
        $token = $this->userA->createToken('test', ['tenant:'.$this->tenantA->id])->plainTextToken;

        $create = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantA->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/workspaces', ['name' => 'API Workspace', 'slug' => 'api-workspace']);

        $create->assertStatus(201);
        $id = $create->json('data.id');

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantA->id,
        ])->getJson('/api/v1/workspaces/'.$id);
        $show->assertStatus(200)->assertJson(['data' => ['slug' => 'api-workspace']]);

        $update = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantA->id,
        ])->putJson('/api/v1/workspaces/'.$id, ['name' => 'Updated', 'slug' => 'updated-slug']);
        $update->assertStatus(200);

        $delete = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenantA->id,
        ])->deleteJson('/api/v1/workspaces/'.$id);
        $delete->assertStatus(204);
    }
}
