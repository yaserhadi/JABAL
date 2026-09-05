<?php

namespace Tests\Concerns;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithDedicatedSecurityRbac
{
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

    protected function assignSecurityPolicyAdmin(TenantUser $user, Tenant $tenant): void
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
}
