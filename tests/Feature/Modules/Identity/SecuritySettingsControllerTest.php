<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Identity\Models\UserSession;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** BK-035: SecuritySettingsController — tenant security settings UI hub. */
class SecuritySettingsControllerTest extends TestCase
{
    protected User $admin;

    protected User $member;

    protected User $otherUser;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurityPolicyRbac();
        $this->admin = $this->registerTenantUser('Admin', 'admin-'.uniqid().'@example.com');
        $this->tenant = $this->admin->personalTenant();
        $this->assignSecurityPolicyAdmin($this->admin, $this->tenant);

        $this->member = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Member User',
            'email' => 'member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->member, $this->tenant, 'member', 'active');
        $this->assignMemberRole($this->member, $this->tenant);

        $otherAdmin = $this->registerTenantUser('Other Admin', 'other-admin-'.uniqid().'@example.com');
        $this->otherTenant = $otherAdmin->personalTenant();

        $this->otherUser = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other User',
            'email' => 'other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->otherUser, $this->otherTenant, 'member', 'active');
    }

    public function test_security_settings_page_renders_for_tenant_member(): void
    {
        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SecuritySettings/Index')
            ->has('tenant')
            ->has('sessions')
            ->has('mfa')
            ->has('tokens'));
    }

    public function test_policies_prop_present_for_admin_with_view_permission(): void
    {
        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SecuritySettings/Index')
            ->where('policies.mfa_required', false)
            ->where('policies.session_idle_timeout', -1));
    }

    public function test_policies_prop_null_for_member_without_view_permission(): void
    {
        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SecuritySettings/Index')
            ->where('policies', null));
    }

    public function test_admin_can_update_policies_via_settings_route(): void
    {
        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patch('/t/'.$this->tenant->id.'/security/settings/policies', [
                'session_idle_timeout' => 45,
            ]);

        $response->assertRedirect($this->tenantNamedRouteUrl('identity.security-settings.show', $this->tenant));
        $response->assertSessionHas('success');

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings')
            ->assertInertia(fn ($page) => $page->where('policies.session_idle_timeout', 45));
    }

    public function test_member_cannot_update_policies(): void
    {
        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->patch('/t/'.$this->tenant->id.'/security/settings/policies', [
                'session_idle_timeout' => 45,
            ]);

        $response->assertForbidden();
    }

    public function test_sessions_list_shows_only_authenticated_user_sessions(): void
    {
        tenancy()->initialize($this->tenant);

        UserSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'session_id' => 'member-session-1',
            'device_label' => 'Chrome on Windows',
            'ip_address' => '10.0.0.1',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        UserSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'session_id' => 'admin-session-1',
            'device_label' => 'Firefox on macOS',
            'ip_address' => '10.0.0.2',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        tenancy()->end();

        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertInertia(fn ($page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.device_label', 'Chrome on Windows'));
    }

    public function test_session_revoke_succeeds_for_own_session(): void
    {
        tenancy()->initialize($this->tenant);

        $record = UserSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'session_id' => 'other-device',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        tenancy()->end();

        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/security/settings/sessions/'.$record->id);

        $response->assertRedirect($this->tenantNamedRouteUrl('identity.security-settings.show', $this->tenant));

        tenancy()->initialize($this->tenant);
        $this->assertNotNull($record->refresh()->revoked_at);
        tenancy()->end();
    }

    public function test_session_revoke_fails_for_another_users_session_idor(): void
    {
        tenancy()->initialize($this->tenant);

        $adminSession = UserSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'session_id' => 'admin-only',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        tenancy()->end();

        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/security/settings/sessions/'.$adminSession->id);

        $response->assertNotFound();
    }

    public function test_session_revoke_fails_cross_tenant(): void
    {
        tenancy()->initialize($this->otherTenant);

        $otherTenantSession = UserSession::create([
            'tenant_id' => $this->otherTenant->id,
            'user_id' => $this->otherUser->id,
            'session_id' => 'other-tenant-sess',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        tenancy()->end();

        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/security/settings/sessions/'.$otherTenantSession->id);

        $response->assertNotFound();
    }

    public function test_revoke_other_sessions_keeps_current_browser_session(): void
    {
        $this->actingAsTenantUser($this->member, $this->tenant);
        $this->get('/t/'.$this->tenant->id.'/security/settings');

        $other = UserSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'session_id' => 'other-browser',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $response = $this->delete('/t/'.$this->tenant->id.'/security/settings/sessions');
        $response->assertRedirect($this->tenantNamedRouteUrl('identity.security-settings.show', $this->tenant));

        $this->assertNotNull($other->refresh()->revoked_at);

        $reload = $this->get('/t/'.$this->tenant->id.'/security/settings');
        $reload->assertOk();
        $reload->assertInertia(fn ($page) => $page->component('SecuritySettings/Index'));
    }

    public function test_mfa_status_props_populated(): void
    {
        $response = $this->actingAsTenantUser($this->member, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertInertia(fn ($page) => $page
            ->has('mfa.available')
            ->has('mfa.required')
            ->has('mfa.enrolled')
            ->where('mfa.enrolled', false));
    }

    public function test_api_tokens_list_safe_fields_only(): void
    {
        $this->actingAsTenantUser($this->member, $this->tenant);
        $this->member->createToken('ui-test-token', ['tenant:'.$this->tenant->id, 'extra:ability']);

        $response = $this->get('/t/'.$this->tenant->id.'/security/settings');

        $response->assertInertia(fn ($page) => $page
            ->has('tokens', 1)
            ->where('tokens.0.name', 'ui-test-token')
            ->has('tokens.0.id')
            ->has('tokens.0.created_at')
            ->has('tokens.0.last_used_at')
            ->has('tokens.0.expires_at')
            ->missing('tokens.0.abilities'));
    }

    public function test_unauthenticated_access_redirects_to_login(): void
    {
        $response = $this->get('/t/'.$this->tenant->id.'/security/settings');
        $response->assertRedirect($this->tenantLoginRedirectUri($this->tenant));
    }

    protected function seedSecurityPolicyRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach ([
            'tenant.security-policy.view',
            'tenant.security-policy.update',
            'dashboard.view',
            'workspace.view',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignSecurityPolicyAdmin(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $guard = config('auth.defaults.guard');
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        foreach (['tenant.security-policy.view', 'tenant.security-policy.update', 'dashboard.view', 'workspace.view'] as $perm) {
            $p = Permission::findByName($perm, $guard);
            if ($p && ! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    protected function assignMemberRole(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $guard = config('auth.defaults.guard');
        $role = Role::firstOrCreate(
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        foreach (['dashboard.view', 'workspace.view'] as $perm) {
            $p = Permission::findByName($perm, $guard);
            if ($p && ! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
