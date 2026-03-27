<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3B: Seed initial RBAC catalog (permissions and roles).
 *
 * Bootstrap/seeding may assign roles explicitly using tenant_id under controlled setup code.
 * Runs on central connection; RBAC tables are in jabal_central.
 */
class RbacCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();

        foreach (Tenant::all() as $tenant) {
            $provisioner->ensureRolesForTenant($tenant);
        }

        $this->assignAdminRole();
    }

    /**
     * Assign tenant-admin role to admin user in their personal tenant.
     * Uses personalTenant() relation (type=personal, membership_type=owner) —
     * not slug derivation — for reliable lookup.
     */
    protected function assignAdminRole(): void
    {
        $user = User::where('email', config('app.admin_email', 'admin@example.com'))->first();
        $tenant = $user?->personalTenant();

        if (! $user || ! $tenant) {
            return;
        }

        app(TenantRbacProvisioner::class)->assignTenantAdminRole($user, $tenant);
    }
}
