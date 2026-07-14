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
    /**
     * BK-064: Resolve home Tenant via Membership (not personal type / created_by SSOT).
     */
    public function resolveHomeTenant(TenantUser $user): ?Tenant
    {
        return $user->homeTenant();
    }

    /**
     * @deprecated BK-064 — use resolveHomeTenant().
     */
    public function getPersonalTenant(TenantUser $user): ?Tenant
    {
        return $this->resolveHomeTenant($user);
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

    /**
     * @deprecated BK-064 — registration creates home tenant; do not invent personal type.
     */
    public function createPersonalTenant(TenantUser $user): Tenant
    {
        $tenant = $this->resolveHomeTenant($user);
        if ($tenant) {
            return $tenant;
        }

        throw new \RuntimeException('User has no home tenant membership.');
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
