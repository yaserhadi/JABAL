<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

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
     * @return \Illuminate\Database\Eloquent\Collection<int, Tenant>
     */
    public function getTenants(TenantUser $user): \Illuminate\Database\Eloquent\Collection
    {
        $tenantIds = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('tenant_id');

        if ($tenantIds->isNotEmpty()) {
            return Tenant::query()->whereIn('id', $tenantIds)->get();
        }

        if ($user->tenant_id) {
            $home = Tenant::query()->find($user->tenant_id);

            return $home ? new \Illuminate\Database\Eloquent\Collection([$home]) : new \Illuminate\Database\Eloquent\Collection;
        }

        return new \Illuminate\Database\Eloquent\Collection;
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
    ): Membership {
        return app(MembershipService::class)->create(
            $user->id,
            $tenant->id,
            $membershipType,
            $status
        );
    }

    public function removeUserFromTenant(TenantUser $user, Tenant $tenant): bool
    {
        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $membership) {
            return false;
        }

        app(MembershipService::class)->remove($membership, $tenant);

        return true;
    }
}
