<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetting;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3D: Tenant settings (central tenant_settings, RBAC, API, audit).
 */
class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $memberUser;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSettingsRbac();
        $this->owner = User::factory()->create();
        $this->memberUser = User::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);
        $this->createMembership($this->memberUser, $this->tenant, 'member', 'active');
    }

    protected function seedSettingsRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach ([
            'tenant.settings.view',
            'tenant.settings.update',
            'dashboard.view',
            'workspace.view',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], ['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignTenantAdmin(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach (['tenant.settings.view', 'tenant.settings.update', 'dashboard.view', 'workspace.view'] as $perm) {
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

    protected function assignMember(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'member', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => 'member', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach (['dashboard.view', 'workspace.view'] as $perm) {
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

    public function test_tenant_admin_can_view_settings_web(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->get('/t/'.$this->tenant->id.'/settings');
        $response->assertStatus(200);
    }

    public function test_member_cannot_view_settings_web(): void
    {
        $this->assignMember($this->memberUser, $this->tenant);
        $this->actingAs($this->memberUser);
        tenancy()->initialize($this->tenant);

        $response = $this->get('/t/'.$this->tenant->id.'/settings');
        $response->assertStatus(403);
    }

    public function test_member_cannot_patch_settings_web(): void
    {
        $this->assignMember($this->memberUser, $this->tenant);
        $this->actingAs($this->memberUser);
        tenancy()->initialize($this->tenant);

        $response = $this->patch('/t/'.$this->tenant->id.'/settings', [
            'display_name' => 'Hacker',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_admin_can_patch_settings_web(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->patch('/t/'.$this->tenant->id.'/settings', [
            'display_name' => 'Acme Corp',
            'timezone' => 'UTC',
            'locale' => 'en',
            'branding_logo_url' => 'https://example.com/logo.png',
        ]);

        $response->assertRedirect();
        $row = TenantSetting::query()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Acme Corp', $row->display_name);
        $this->assertSame('UTC', $row->timezone);
    }

    public function test_invalid_timezone_rejected(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/settings', [
            'timezone' => 'Not/A_Real_Zone',
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_locale_rejected(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/settings', [
            'locale' => 'zz',
        ]);

        $response->assertStatus(422);
    }

    public function test_audit_logged_on_update(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $this->patch('/t/'.$this->tenant->id.'/settings', [
            'display_name' => 'First',
            'timezone' => 'UTC',
            'locale' => 'en',
        ])->assertRedirect();

        $this->assertTrue(AuditLog::query()->where('event', 'tenant_settings.created')->exists());

        $this->patch('/t/'.$this->tenant->id.'/settings', [
            'display_name' => 'Second',
            'timezone' => 'UTC',
            'locale' => 'en',
        ])->assertRedirect();

        $this->assertTrue(AuditLog::query()->where('event', 'tenant_settings.updated')->exists());
    }

    public function test_api_settings_requires_matching_tenant_header(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $tenantB = $this->createPersonalTenant(User::factory()->create());
        $token = $this->owner->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenantB->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/tenants/current/settings');

        $response->assertStatus(403)
            ->assertJson(['error' => 'Header does not match token ability']);
    }

    public function test_api_admin_can_get_and_patch_settings(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $token = $this->owner->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $get = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/tenants/current/settings');

        $get->assertStatus(200)->assertJsonPath('data.locale', 'en');

        $patch = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->patchJson('/api/v1/tenants/current/settings', [
            'display_name' => 'API Tenant',
            'timezone' => 'Africa/Cairo',
            'locale' => 'ar',
        ]);

        $patch->assertStatus(200)->assertJsonPath('data.display_name', 'API Tenant');
    }

    public function test_member_removal_mode_can_be_updated(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->patch('/t/'.$this->tenant->id.'/settings', [
            'member_removal_mode' => 'reversible',
            'timezone' => 'UTC',
            'locale' => 'en',
        ]);

        $response->assertRedirect();
        $this->assertSame('reversible', app(\Modules\Tenancy\Services\TenantSettingsService::class)->memberRemovalMode($this->tenant));
    }

    public function test_invalid_member_removal_mode_rejected(): void
    {
        $this->assignTenantAdmin($this->owner, $this->tenant);
        $this->actingAs($this->owner);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/settings', [
            'member_removal_mode' => 'soft_delete',
        ]);

        $response->assertStatus(422);
    }
}
