<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityFeatureGateTest extends TestCase
{
    public function test_billing_catalog_includes_sso_available_entitlement(): void
    {
        $this->seed(\Database\Seeders\BillingCatalogSeeder::class);

        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();

        $this->assertTrue(
            Entitlement::query()
                ->where('plan_id', $plan->id)
                ->where('code', 'sso_available')
                ->exists()
        );
    }

    public function test_is_sso_available_reflects_active_subscription_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'is_active' => true,
        ]);

        Entitlement::query()->create([
            'id' => Str::uuid()->toString(),
            'plan_id' => $plan->id,
            'code' => 'sso_available',
            'name' => 'sso_available',
            'is_active' => true,
        ]);

        $gate = app(SecurityFeatureGate::class);
        $this->assertFalse($gate->isSsoAvailable($tenant));

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->assertTrue($gate->isSsoAvailable($tenant));
    }

    public function test_tenant_admin_role_includes_sso_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);

        $guard = config('auth.defaults.guard');
        foreach (['tenant.sso.view', 'tenant.sso.update'] as $perm) {
            $this->assertNotNull(Permission::findByName($perm, $guard));
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::query()
            ->where('name', 'tenant-admin')
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('tenant.sso.view'));
        $this->assertTrue($role->hasPermissionTo('tenant.sso.update'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
