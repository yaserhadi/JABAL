<?php

namespace Modules\Identity\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * UserService handles all user-related business logic.
 *
 * This service manages user-tenant relationships and membership operations,
 * keeping the User model clean of business logic (Lock 2 compliance).
 */
class UserService
{
    /**
     * Get a user's personal tenant.
     *
     * Returns the tenant where type is 'personal' and the user is the owner.
     */
    public function getPersonalTenant(User $user): ?Tenant
    {
        return Tenant::whereHas('tenantUsers', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('membership_type', 'owner')
                ->where('status', 'active');
        })
            ->where('type', 'personal')
            ->first();
    }

    /**
     * Get all tenants the user belongs to.
     *
     * @return Collection<int, Tenant>
     */
    public function getTenants(User $user): Collection
    {
        return Tenant::whereHas('tenantUsers', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('status', 'active');
        })->get();
    }

    /**
     * Get all tenant memberships for a user.
     *
     * @return Collection<int, TenantUser>
     */
    public function getTenantMemberships(User $user): Collection
    {
        return TenantUser::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('tenant')
            ->get();
    }

    /**
     * Get a specific tenant membership for a user.
     */
    public function getTenantMembership(User $user, Tenant $tenant): ?TenantUser
    {
        return TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    /**
     * Check if user is an owner of a specific tenant.
     */
    public function isOwner(User $user, Tenant $tenant): bool
    {
        return TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if user is an admin of a specific tenant.
     */
    public function isAdmin(User $user, Tenant $tenant): bool
    {
        return TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->whereIn('membership_type', ['owner', 'admin'])
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Get user's membership type for a specific tenant.
     */
    public function getMembershipType(User $user, Tenant $tenant): ?string
    {
        $membership = $this->getTenantMembership($user, $tenant);

        return $membership?->membership_type;
    }

    /**
     * Create a personal tenant for a user.
     *
     * PHASE 2: Sets status = 'active' and created_by = user ID.
     */
    public function createPersonalTenant(User $user): Tenant
    {
        $tenant = Tenant::create([
            'name' => $user->name."'s Workspace",
            'slug' => 'personal-'.$user->id,
            'type' => 'personal',
            'isolation_level' => 'shared',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $tenant;
    }

    /**
     * Add a user to a tenant with specified membership type.
     */
    public function addUserToTenant(
        User $user,
        Tenant $tenant,
        string $membershipType = 'member',
        string $status = 'active'
    ): TenantUser {
        return TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => $membershipType,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    /**
     * Remove a user from a tenant.
     */
    public function removeUserFromTenant(User $user, Tenant $tenant): bool
    {
        return TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->delete() > 0;
    }
}
