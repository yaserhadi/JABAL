<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3B: Seed initial RBAC catalog (permissions and roles).
 *
 * Bootstrap/seeding may assign roles explicitly using tenant_id under controlled setup code.
 * Runs on tenant connection; RBAC tables are in jabal_tenant_shared.
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
    }
}
