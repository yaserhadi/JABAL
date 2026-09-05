<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\TenantUser;
use App\Support\Contracts\Billing\TenantSeatLimitResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Modules\Tenancy\Services\TenantSettingsService;
use Spatie\Permission\PermissionRegistrar;

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
        string $status = 'active',
        bool $skipSeatCheck = false
    ): Membership {
        if ($status === 'active' && ! $skipSeatCheck) {
            $this->assertSeatCapacity($tenantId);
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $existing = Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                if ($existing->status === 'active') {
                    return $existing;
                }

                $existing->update([
                    'membership_type' => $membershipType,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                return $existing->fresh();
            }

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

    public function activeMemberCount(string $tenantId): int
    {
        return $this->withTenantContext($tenantId, function () use ($tenantId) {
            return Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->count();
        });
    }

    public function pendingInvitationCount(string $tenantId): int
    {
        return $this->withTenantContext($tenantId, function () use ($tenantId) {
            return TenantInvitation::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->pending()
                ->count();
        });
    }

    /**
     * @return string Removal mode applied: permanent|reversible
     */
    public function remove(Membership $membership, Tenant $tenant): string
    {
        $mode = app(TenantSettingsService::class)->memberRemovalMode($tenant);

        $this->withTenantContext($tenant->id, function () use ($membership, $tenant, $mode) {
            DB::connection('tenant')->transaction(function () use ($membership, $tenant, $mode) {
                $membership->refresh();
                $this->assertNotLastActiveOwner($membership, $tenant);
                $this->clearMemberRoles($membership, $tenant);

                if ($mode === TenantSettingsService::REMOVAL_MODE_REVERSIBLE) {
                    $membership->update([
                        'status' => 'removed',
                        'removed_at' => $membership->removed_at ?? now(),
                    ]);

                    return;
                }

                $membership->delete();
            });
        });

        return $mode;
    }

    public function restore(Membership $membership, Tenant $tenant): void
    {
        if ($membership->status !== 'removed') {
            throw new InvalidArgumentException('Member is not in removed status.');
        }

        $this->withTenantContext($tenant->id, function () use ($membership, $tenant) {
            $this->assertSeatCapacity($tenant->id);

            DB::connection('tenant')->transaction(function () use ($membership, $tenant) {
                $membership->refresh();

                if ($membership->status !== 'removed') {
                    throw new InvalidArgumentException('Member is not in removed status.');
                }

                $membership->update([
                    'status' => 'active',
                    'removed_at' => null,
                    'joined_at' => $membership->joined_at ?? now(),
                ]);

                $user = TenantUser::withoutGlobalScope('tenant')->find($membership->user_id);
                if ($user) {
                    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
                    try {
                        $user->syncRoles(['member']);
                    } finally {
                        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
                    }
                }
            });
        });
    }

    public function deleteForever(Membership $membership, Tenant $tenant): void
    {
        if ($membership->status !== 'removed') {
            throw new InvalidArgumentException('Member is not in removed status.');
        }

        $this->withTenantContext($tenant->id, function () use ($membership, $tenant) {
            DB::connection('tenant')->transaction(function () use ($membership, $tenant) {
                $membership->refresh();
                $this->assertCanPermanentlyDeleteRemovedOwner($membership, $tenant);
                $membership->delete();
            });
        });
    }

    public function transferOwnership(Tenant $tenant, TenantUser $fromUser, TenantUser $toUser): void
    {
        $this->withTenantContext($tenant->id, function () use ($tenant, $fromUser, $toUser) {
            DB::connection('tenant')->transaction(function () use ($tenant, $fromUser, $toUser) {
                $actorMembership = Membership::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $fromUser->id)
                    ->first();

                $targetMembership = Membership::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $toUser->id)
                    ->first();

                if (! $actorMembership?->isOwner() || $actorMembership->status !== 'active') {
                    throw new InvalidArgumentException('Only an active owner may transfer ownership.');
                }

                if (! $targetMembership || $targetMembership->status !== 'active' || $targetMembership->isOwner()) {
                    throw new InvalidArgumentException('Target must be an active non-owner member.');
                }

                $actorMembership->update(['membership_type' => 'member']);
                $targetMembership->update(['membership_type' => 'owner']);

                app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
                try {
                    $fromUser->syncRoles(['member']);
                    $toUser->syncRoles(['tenant-admin']);
                    app(TenantRbacProvisioner::class)->assignTenantAdminRole($toUser, $tenant);
                } finally {
                    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
                }
            });
        });
    }

    public function assertSeatCapacityForInvitation(string $tenantId): void
    {
        $this->assertSeatCapacity($tenantId, includePendingInvitation: true);
    }

    protected function assertSeatCapacity(string $tenantId, bool $includePendingInvitation = false): void
    {
        $limit = app(TenantSeatLimitResolver::class)->seatLimitForTenant($tenantId);
        if ($limit === null) {
            return;
        }

        $used = $this->activeMemberCount($tenantId);
        if ($includePendingInvitation) {
            $used += $this->pendingInvitationCount($tenantId);
        }

        if ($used >= $limit) {
            throw new InvalidArgumentException('Seat limit reached for this tenant.');
        }
    }

    protected function assertNotLastActiveOwner(Membership $membership, Tenant $tenant): void
    {
        if ($membership->status !== 'active' || ! $membership->isOwner()) {
            return;
        }

        $ownerCount = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->count();

        if ($ownerCount <= 1) {
            throw new InvalidArgumentException('Cannot remove the last owner of the tenant.');
        }
    }

    protected function assertCanPermanentlyDeleteRemovedOwner(Membership $membership, Tenant $tenant): void
    {
        if (! $membership->isOwner()) {
            return;
        }

        $hasOtherActiveOwner = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->where('user_id', '!=', $membership->user_id)
            ->exists();

        if (! $hasOtherActiveOwner) {
            throw new InvalidArgumentException('Cannot remove the last owner of the tenant.');
        }
    }

    protected function clearMemberRoles(Membership $membership, Tenant $tenant): void
    {
        $user = TenantUser::withoutGlobalScope('tenant')->find($membership->user_id);
        if (! $user) {
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        try {
            $user->syncRoles([]);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withTenantContext(string $tenantId, callable $callback)
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return $callback();
        }

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return $callback();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }
}
