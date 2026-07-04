<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** BK-043: SecurityPolicyController — tenant-facing security policies API. */
class SecurityPolicyControllerTest extends TestCase
{
    protected User $admin;

    protected User $member;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurityPolicyRbac();
        $this->admin = $this->registerTenantUser('Admin', 'admin-'.uniqid().'@example.com');
        $this->tenant = $this->admin->personalTenant();
        $this->assignSecurityPolicyAdmin($this->admin, $this->tenant);

        $this->member = $this->registerTenantUser('Member', 'member-'.uniqid().'@example.com');
        $this->createMembership($this->member, $this->tenant, 'member', 'active');
        $this->assignMemberRole($this->member, $this->tenant);
    }

    public function test_admin_can_view_security_policies(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->getJson('/t/'.$this->tenant->id.'/security/policies');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['mfa_required', 'mfa_grace_period_days', 'password_policy', 'session_idle_timeout']]);
        $response->assertJsonPath('data.mfa_required', false);
        $response->assertJsonPath('data.session_idle_timeout', -1);
    }

    public function test_member_cannot_view_security_policies(): void
    {
        $this->actingAs($this->member);
        tenancy()->initialize($this->tenant);

        $response = $this->getJson('/t/'.$this->tenant->id.'/security/policies');
        $response->assertForbidden();
    }

    public function test_admin_can_update_individual_field(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => 60,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.session_idle_timeout', 60);
        $response->assertJsonPath('data.mfa_required', false);
    }

    public function test_admin_can_update_multiple_fields(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => 30,
            'mfa_grace_period_days' => 7,
            'password_policy' => [
                'min_length' => 12,
                'require_uppercase' => true,
                'require_number' => true,
                'require_special' => false,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.session_idle_timeout', 30);
        $response->assertJsonPath('data.mfa_grace_period_days', 7);
        $response->assertJsonPath('data.password_policy.min_length', 12);
        $response->assertJsonPath('data.password_policy.require_uppercase', true);
    }

    public function test_validation_rejects_invalid_types(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'mfa_required' => 'not-boolean',
        ]);

        $response->assertUnprocessable();
    }

    public function test_validation_rejects_out_of_range_values(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => 9999,
        ]);
        $response->assertUnprocessable();

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'mfa_grace_period_days' => -5,
        ]);
        $response->assertUnprocessable();
    }

    public function test_unsupported_fields_rejected(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'password_expiry_days' => 90,
        ]);

        $response->assertUnprocessable();
    }

    public function test_member_cannot_update_security_policies(): void
    {
        $this->actingAs($this->member);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => 60,
        ]);

        $response->assertForbidden();
    }

    public function test_mfa_required_rejected_without_entitlement(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'mfa_required' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_mfa_required_accepted_with_entitlement(): void
    {
        $this->grantMfaAvailable($this->tenant);
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'mfa_required' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mfa_required', true);
    }

    public function test_session_idle_timeout_minus_one_accepted(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => -1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.session_idle_timeout', -1);
    }

    public function test_session_idle_timeout_negative_other_than_minus_one_rejected(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'session_idle_timeout' => -2,
        ]);

        $response->assertUnprocessable();
    }

    public function test_mfa_grace_period_zero_accepted(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'mfa_grace_period_days' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mfa_grace_period_days', 0);
    }

    public function test_password_policy_partial_object_validation(): void
    {
        $this->actingAs($this->admin);
        tenancy()->initialize($this->tenant);

        $response = $this->patchJson('/t/'.$this->tenant->id.'/security/policies', [
            'password_policy' => [
                'min_length' => 10,
            ],
        ]);

        $response->assertUnprocessable();
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

    protected function grantMfaAvailable(Tenant $tenant): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'mfa-test'],
            ['name' => 'MFA Test', 'is_active' => true]
        );

        Entitlement::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'code' => 'mfa_available'],
            ['name' => 'MFA Available', 'is_active' => true]
        );

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );
    }
}
