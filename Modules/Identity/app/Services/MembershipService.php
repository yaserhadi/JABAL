<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;

class MembershipService
{
    public function hasActiveMembership(string $userId, string $tenantId): bool
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return false;
        }

        $wasInitialized = tenancy()->initialized;
        $previousTenant = tenancy()->tenant;

        if (! $wasInitialized || tenancy()->tenant?->id !== $tenantId) {
            tenancy()->initialize($tenant);
        }

        try {
            return Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previousTenant && $previousTenant->id !== $tenantId) {
                tenancy()->initialize($previousTenant);
            }
        }
    }

    public function create(
        string $userId,
        string $tenantId,
        string $membershipType = 'member',
        string $status = 'active'
    ): Membership {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return Membership::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'membership_type' => $membershipType,
                'status' => $status,
                'joined_at' => $status === 'active' ? now() : null,
            ]);
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    public function findForUserAndTenant(string $userId, string $tenantId): ?Membership
    {
        return Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }
}
