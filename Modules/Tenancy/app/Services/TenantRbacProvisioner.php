<?php

namespace Modules\Tenancy\Services;

use App\Models\User;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures global permissions exist and tenant-scoped roles match the catalog.
 * Used by RbacCatalogSeeder and when new tenants are created (e.g. registration).
 */
class TenantRbacProvisioner
{
    /** @var array<int, string> */
    protected array $permissions = [
        'workspace.view',
        'workspace.create',
        'workspace.update',
        'workspace.delete',
        'dashboard.view',
        'member.view',
        'member.assign-role',
        'member.suspend',
        'tenant.settings.view',
        'tenant.settings.update',
    ];

    /** @var array<string, array<int, string>> */
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
            'tenant.settings.view',
            'tenant.settings.update',
        ],
        'member' => ['workspace.view', 'dashboard.view'],
    ];

    public function __construct(
        private PermissionRegistrar $permissionRegistrar
    ) {}

    public function ensureGlobalPermissions(): void
    {
        $guard = config('auth.defaults.guard');

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['name' => $name, 'guard_name' => $guard]
            );
        }
    }

    public function ensureRolesForTenant(Tenant $tenant): void
    {
        $guard = config('auth.defaults.guard');

        $this->permissionRegistrar->setPermissionsTeamId($tenant->getTenantKey());

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

        $this->permissionRegistrar->setPermissionsTeamId(null);
    }

    /**
     * Assign the tenant-admin Spatie role for this tenant to the user (idempotent).
     */
    public function assignTenantAdminRole(User $user, Tenant $tenant): void
    {
        $guard = config('auth.defaults.guard');

        $this->permissionRegistrar->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::where('name', 'tenant-admin')->where('tenant_id', $tenant->id)->first();
        if ($role && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        $this->permissionRegistrar->setPermissionsTeamId(null);
    }
}
