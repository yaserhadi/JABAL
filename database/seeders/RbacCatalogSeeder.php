<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3B: Seed initial RBAC catalog (permissions and roles).
 *
 * Bootstrap/seeding may assign roles explicitly using tenant_id under controlled setup code.
 * Runs on central connection; RBAC tables are in jabal_central.
 */
class RbacCatalogSeeder extends Seeder
{
    protected array $permissions = [
        'workspace.view',
        'workspace.create',
        'workspace.update',
        'workspace.delete',
        'dashboard.view',
        'member.view',
        'member.assign-role',
        'member.suspend',
    ];

    protected array $rolePermissions = [
        'tenant-admin' => [
            'workspace.view',
            'workspace.create',
            'workspace.update',
            'workspace.delete',
            'dashboard.view',
            'member.view',
            'member.assign-role',
            'member.suspend',
        ],
        'member' => ['workspace.view', 'dashboard.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard');

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['name' => $name, 'guard_name' => $guard]
            );
        }

        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());

            foreach ($this->rolePermissions as $roleName => $permissionNames) {
                $role = Role::firstOrCreate(
                    [
                        'name' => $roleName,
                        'guard_name' => $guard,
                        'tenant_id' => $tenant->id,
                    ],
                    ['name' => $roleName, 'guard_name' => $guard, 'tenant_id' => $tenant->id]
                );

                foreach ($permissionNames as $perm) {
                    $permission = Permission::findByName($perm, $guard);
                    if (! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                    }
                }
            }
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

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

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::where('name', 'tenant-admin')->where('tenant_id', $tenant->id)->first();
        if ($role && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
