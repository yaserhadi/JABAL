<?php

namespace Modules\Identity\Services;

use App\Models\User;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser as TenantMembership;
use Modules\Tenancy\Services\TenantRbacProvisioner;

class TenantRegistrationService
{
    public function registerTenantUser(string $name, string $email, string $password): User
    {
        $tenant = Tenant::create([
            'name' => $name."'s Workspace",
            'slug' => 'pending-'.uniqid(),
            'type' => 'personal',
            'isolation_level' => 'shared',
            'status' => 'active',
        ]);

        tenancy()->initialize($tenant);

        try {
            $tenantUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant->update([
                'created_by' => $tenantUser->id,
                'slug' => 'personal-'.$tenantUser->id,
            ]);

            TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $tenantUser->id,
                'membership_type' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $rbac = app(TenantRbacProvisioner::class);
            $rbac->ensureGlobalPermissions();
            $rbac->ensureRolesForTenant($tenant);
            $rbac->assignTenantAdminRole($tenantUser, $tenant);

            return User::withoutGlobalScope('tenant')->findOrFail($tenantUser->getKey());
        } finally {
            tenancy()->end();
        }
    }
}
