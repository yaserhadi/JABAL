<?php

namespace Modules\Identity\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Identity\Models\Membership;
use Modules\Identity\Services\MembershipService;
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
        return Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->delete() > 0;
    }
}
