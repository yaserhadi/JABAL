<?php

namespace Modules\Identity\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser as TenantMembership;
use Modules\Tenancy\Services\TenantRbacProvisioner;

/**
 * Tenant Application user operations (ADR-0007).
 */
class UserService
{
    public function getPersonalTenant(TenantUser $user): ?Tenant
    {
        return $user->personalTenant()
            ?? Tenant::query()->find($user->tenant_id);
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(TenantUser $user): Collection
    {
        $tenants = Tenant::whereHas('tenantUsers', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('status', 'active');
        })->get();

        if ($tenants->isNotEmpty()) {
            return $tenants;
        }

        if ($user->tenant_id) {
            $home = Tenant::query()->find($user->tenant_id);

            return $home ? new Collection([$home]) : new Collection;
        }

        return new Collection;
    }

    public function createPersonalTenant(TenantUser $user): Tenant
    {
        $tenant = $user->personalTenant();
        if ($tenant) {
            return $tenant;
        }

        throw new \RuntimeException('User has no personal tenant.');
    }

    public function addUserToTenant(
        TenantUser $user,
        Tenant $tenant,
        string $membershipType = 'member',
        string $status = 'active'
    ): TenantMembership {
        return TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => $membershipType,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    public function removeUserFromTenant(TenantUser $user, Tenant $tenant): bool
    {
        return TenantMembership::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->delete() > 0;
    }
}
