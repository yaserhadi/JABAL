<?php

namespace Modules\Tenancy\Services;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use InvalidArgumentException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser as TenantApplicationUser;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source for the tenant RBAC catalog (permission names and role matrices).
 * Ensures global permissions exist and tenant-scoped roles match the catalog.
 * Used by RbacCatalogSeeder and when new tenants are created (e.g. registration).
 * Do not duplicate this catalog in seeders—extend here only.
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
        'member.invite',
        'member.remove',
        'tenant.settings.view',
        'tenant.settings.update',
        'tenant.audit.view',
        'tenant.security-policy.view',
        'tenant.security-policy.update',
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
            'member.invite',
            'member.remove',
            'tenant.settings.view',
            'tenant.settings.update',
            'tenant.audit.view',
            'tenant.security-policy.view',
            'tenant.security-policy.update',
        ],
        'member' => ['workspace.view', 'dashboard.view'],
    ];

    public function __construct(
        private PermissionRegistrar $permissionRegistrar
    ) {}

    /**
     * Create any catalog permissions missing from the DB (idempotent; skips work when complete).
     */
    public function ensureGlobalPermissions(): void
    {
        $guard = config('auth.defaults.guard');

        $existing = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $this->permissions)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($this->permissions, $existing));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $name) {
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

        try {
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
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId(null);
        }
    }

    /**
     * Assign the tenant-admin Spatie role for this tenant to the user (idempotent).
     * Caller must ensure roles exist for the tenant (e.g. ensureRolesForTenant first).
     *
     * @throws InvalidArgumentException If the user is not an active owner of the tenant.
     */
    public function assignTenantAdminRole(TenantApplicationUser $user, Tenant $tenant): void
    {
        if (! $this->userIsActiveOwner($user, $tenant)) {
            throw new InvalidArgumentException(
                'assignTenantAdminRole requires an active owner membership for the user and tenant.'
            );
        }

        $this->permissionRegistrar->setPermissionsTeamId($tenant->getTenantKey());

        try {
            $role = Role::where('name', 'tenant-admin')->where('tenant_id', $tenant->id)->first();
            if ($role && ! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId(null);
        }
    }

    protected function userIsActiveOwner(TenantApplicationUser $user, Tenant $tenant): bool
    {
        return Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->exists();
    }
}
