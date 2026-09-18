<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use App\Models\Domain;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantHandleAllocation;
use Modules\Tenancy\Support\TenantHandleValidator;

/**
 * BK-125 Wave 7 — Tenant handle rename + retirement lifecycle (CONFLICT-F).
 *
 * Tenant ID is never changed. Handle is mutable address only.
 */
final class TenantHandleLifecycleService
{
    public function __construct(
        private readonly TenantHandleValidator $handles,
        private readonly TenantDomainProvisioner $domains,
        private readonly TenantHandleDependencyClearGate $releaseGate,
        private readonly TenantAddressingProfile $addressing,
        private readonly AuditLoggerInterface $audit,
    ) {}

    public function quarantineDays(): int
    {
        return max(0, (int) config('tenant_handles.quarantine_days', 30));
    }

    /**
     * Record initial ACTIVE allocation (create / backfill helper).
     */
    public function recordInitialAssignment(Tenant $tenant, ?string $actorId = null): TenantHandleAllocation
    {
        $handle = $this->handles->normalize((string) $tenant->slug);
        if ($handle === '') {
            throw ValidationException::withMessages(['handle' => ['Tenant has no handle to allocate.']]);
        }

        return DB::connection('central')->transaction(function () use ($tenant, $handle, $actorId) {
            $existing = TenantHandleAllocation::query()
                ->where('handle', $handle)
                ->whereIn('status', TenantHandleAllocation::BLOCKING_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existing && (string) $existing->tenant_id !== (string) $tenant->id) {
                throw ValidationException::withMessages([
                    'handle' => ['This Tenant Handle is not available.'],
                ]);
            }

            if ($existing && $existing->status === TenantHandleAllocation::STATUS_ACTIVE) {
                return $existing;
            }

            // Claim AVAILABLE history row or insert fresh ACTIVE.
            $available = TenantHandleAllocation::query()
                ->where('handle', $handle)
                ->where('status', TenantHandleAllocation::STATUS_AVAILABLE)
                ->lockForUpdate()
                ->first();

            if ($available) {
                $available->update([
                    'tenant_id' => $tenant->id,
                    'status' => TenantHandleAllocation::STATUS_ACTIVE,
                    'is_canonical' => true,
                    'assigned_at' => now(),
                    'retired_at' => null,
                    'releasable_at' => null,
                    'released_at' => null,
                    'superseded_by_handle' => null,
                    'actor_id' => $actorId,
                    'reason' => 'assigned',
                ]);
                $allocation = $available->fresh();
            } else {
                $allocation = TenantHandleAllocation::query()->create([
                    'handle' => $handle,
                    'tenant_id' => $tenant->id,
                    'status' => TenantHandleAllocation::STATUS_ACTIVE,
                    'is_canonical' => true,
                    'assigned_at' => now(),
                    'actor_id' => $actorId,
                    'reason' => 'assigned',
                ]);
            }

            $this->audit->log('tenant_handle.assigned', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => TenantHandleAllocation::class,
                'auditable_id' => (string) $allocation->id,
                'new_values' => [
                    'handle' => $handle,
                    'actor_id' => $actorId,
                ],
            ]);

            return $allocation;
        });
    }

    /**
     * Rename Tenant handle. Preserves Tenant ID. Old handle → RETIRED.
     *
     * @return array{tenant: Tenant, old_handle: string, new_handle: string}
     */
    public function rename(Tenant $tenant, string $rawNewHandle, ?string $actorId = null, ?string $reason = null): array
    {
        $newHandle = $this->handles->assertValidForCreate($rawNewHandle);
        $oldHandle = $this->handles->normalize((string) $tenant->slug);

        if ($oldHandle === '') {
            throw ValidationException::withMessages(['handle' => ['Tenant has no current handle.']]);
        }

        if ($newHandle === $oldHandle) {
            throw ValidationException::withMessages(['handle' => ['New handle must differ from the current handle.']]);
        }

        return DB::connection('central')->transaction(function () use ($tenant, $oldHandle, $newHandle, $actorId, $reason) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $current = TenantHandleAllocation::query()
                ->where('tenant_id', $tenant->id)
                ->where('handle', $oldHandle)
                ->where('status', TenantHandleAllocation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $current) {
                // Self-heal missing allocation then rename.
                $current = $this->recordInitialAssignment($tenant, $actorId);
            }

            $blocking = TenantHandleAllocation::query()
                ->where('handle', $newHandle)
                ->whereIn('status', TenantHandleAllocation::BLOCKING_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($blocking) {
                throw ValidationException::withMessages([
                    'handle' => ['This Tenant Handle is not available.'],
                ]);
            }

            $quarantineDays = $this->quarantineDays();
            $retiredAt = now();
            $releasableAt = $retiredAt->copy()->addDays($quarantineDays);

            $current->update([
                'status' => TenantHandleAllocation::STATUS_RETIRED,
                'is_canonical' => false,
                'retired_at' => $retiredAt,
                'releasable_at' => $releasableAt,
                'superseded_by_handle' => $newHandle,
                'actor_id' => $actorId,
                'reason' => $reason ?? 'renamed',
            ]);

            $available = TenantHandleAllocation::query()
                ->where('handle', $newHandle)
                ->where('status', TenantHandleAllocation::STATUS_AVAILABLE)
                ->lockForUpdate()
                ->first();

            if ($available) {
                $available->update([
                    'tenant_id' => $tenant->id,
                    'status' => TenantHandleAllocation::STATUS_ACTIVE,
                    'is_canonical' => true,
                    'assigned_at' => now(),
                    'retired_at' => null,
                    'releasable_at' => null,
                    'released_at' => null,
                    'superseded_by_handle' => null,
                    'actor_id' => $actorId,
                    'reason' => $reason ?? 'renamed',
                ]);
                $newAllocation = $available->fresh();
            } else {
                $newAllocation = TenantHandleAllocation::query()->create([
                    'handle' => $newHandle,
                    'tenant_id' => $tenant->id,
                    'status' => TenantHandleAllocation::STATUS_ACTIVE,
                    'is_canonical' => true,
                    'assigned_at' => now(),
                    'actor_id' => $actorId,
                    'reason' => $reason ?? 'renamed',
                ]);
            }

            $tenant->forceFill(['slug' => $newHandle])->save();

            // Remap platform subdomain domain row: remove old label, provision new.
            Domain::query()->where('domain', $oldHandle)->where('tenant_id', $tenant->id)->delete();
            $this->domains->ensurePlatformSubdomain($tenant->fresh());

            \Illuminate\Support\Facades\Cache::forget('tenant_handle_resolve:'.$oldHandle);
            \Illuminate\Support\Facades\Cache::forget('tenant_handle_resolve:'.$newHandle);

            $this->invalidateAddressBoundSecurityState($tenant, $oldHandle, $newHandle);

            $this->audit->log('tenant_handle.renamed', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => Tenant::class,
                'auditable_id' => (string) $tenant->id,
                'old_values' => ['handle' => $oldHandle],
                'new_values' => [
                    'handle' => $newHandle,
                    'retired_allocation_id' => (string) $current->id,
                    'active_allocation_id' => (string) $newAllocation->id,
                    'actor_id' => $actorId,
                    'reason' => $reason ?? 'renamed',
                ],
            ]);

            return [
                'tenant' => $tenant->fresh(),
                'old_handle' => $oldHandle,
                'new_handle' => $newHandle,
            ];
        });
    }

    /**
     * Evaluate whether a RETIRED allocation may become RELEASABLE / AVAILABLE.
     *
     * @return array{can_release: bool, status: string, quarantine_elapsed: bool, dependencies: array<string, string>, allocation: TenantHandleAllocation}
     */
    public function evaluateRelease(string $handle): array
    {
        $normalized = $this->handles->normalize($handle);
        $allocation = TenantHandleAllocation::query()
            ->where('handle', $normalized)
            ->whereIn('status', [
                TenantHandleAllocation::STATUS_RETIRED,
                TenantHandleAllocation::STATUS_RELEASABLE,
            ])
            ->orderByDesc('retired_at')
            ->first();

        if (! $allocation) {
            throw ValidationException::withMessages([
                'handle' => ['No retired handle allocation found.'],
            ]);
        }

        $quarantineElapsed = $allocation->retired_at !== null
            && $allocation->retired_at->copy()->addDays($this->quarantineDays())->lte(now());

        $deps = $this->releaseGate->evaluate($normalized, $allocation->tenant_id);

        $blocking = array_filter($deps, static fn (string $v): bool => $v === 'release-blocking');
        $canRelease = $quarantineElapsed && $blocking === [];

        if ($canRelease && $allocation->status === TenantHandleAllocation::STATUS_RETIRED) {
            $allocation->update(['status' => TenantHandleAllocation::STATUS_RELEASABLE]);
            $allocation = $allocation->fresh();
            $this->audit->log('tenant_handle.release_gate_evaluated', [
                'tenant_id' => (string) $allocation->tenant_id,
                'auditable_type' => TenantHandleAllocation::class,
                'auditable_id' => (string) $allocation->id,
                'new_values' => [
                    'handle' => $normalized,
                    'result' => 'releasable',
                    'dependencies' => $deps,
                ],
            ]);
        } else {
            $this->audit->log('tenant_handle.release_gate_evaluated', [
                'tenant_id' => (string) $allocation->tenant_id,
                'auditable_type' => TenantHandleAllocation::class,
                'auditable_id' => (string) $allocation->id,
                'new_values' => [
                    'handle' => $normalized,
                    'result' => $canRelease ? 'already_releasable' : 'blocked',
                    'quarantine_elapsed' => $quarantineElapsed,
                    'dependencies' => $deps,
                ],
            ]);
        }

        return [
            'can_release' => $canRelease,
            'status' => (string) $allocation->status,
            'quarantine_elapsed' => $quarantineElapsed,
            'dependencies' => $deps,
            'allocation' => $allocation,
        ];
    }

    /**
     * Transition RELEASABLE → AVAILABLE (claimable by another Tenant).
     */
    public function release(string $handle, ?string $actorId = null): TenantHandleAllocation
    {
        $evaluation = $this->evaluateRelease($handle);
        if (! $evaluation['can_release']) {
            throw ValidationException::withMessages([
                'handle' => ['Handle is not releasable: quarantine or dependencies remain.'],
            ]);
        }

        /** @var TenantHandleAllocation $allocation */
        $allocation = $evaluation['allocation'];

        return DB::connection('central')->transaction(function () use ($allocation, $actorId) {
            $locked = TenantHandleAllocation::query()->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status' => TenantHandleAllocation::STATUS_AVAILABLE,
                'is_canonical' => false,
                'released_at' => now(),
                'actor_id' => $actorId,
                'reason' => 'released',
                // Keep tenant_id for audit provenance of prior ownership.
            ]);

            $this->audit->log('tenant_handle.released', [
                'tenant_id' => (string) $locked->tenant_id,
                'auditable_type' => TenantHandleAllocation::class,
                'auditable_id' => (string) $locked->id,
                'new_values' => [
                    'handle' => $locked->handle,
                    'actor_id' => $actorId,
                ],
            ]);

            return $locked->fresh();
        });
    }

    /**
     * BK-125 Wave 8 — soft-delete retires the active handle and clears tenants.slug.
     * Provenance remains on the RETIRED allocation row (tenant_id + handle + timestamps).
     */
    public function retireOnTenantSoftDelete(Tenant $tenant, ?string $actorId = null): void
    {
        $handle = $this->handles->normalize((string) ($tenant->slug ?? ''));
        if ($handle === '') {
            return;
        }

        DB::connection('central')->transaction(function () use ($tenant, $handle, $actorId) {
            $allocation = TenantHandleAllocation::query()
                ->where('tenant_id', $tenant->id)
                ->where('handle', $handle)
                ->where('status', TenantHandleAllocation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            $quarantineDays = $this->quarantineDays();
            $retiredAt = now();

            if ($allocation) {
                $allocation->update([
                    'status' => TenantHandleAllocation::STATUS_RETIRED,
                    'is_canonical' => false,
                    'retired_at' => $retiredAt,
                    'releasable_at' => $retiredAt->copy()->addDays($quarantineDays),
                    'actor_id' => $actorId,
                    'reason' => 'tenant_soft_deleted',
                ]);
            } else {
                TenantHandleAllocation::query()->create([
                    'handle' => $handle,
                    'tenant_id' => $tenant->id,
                    'status' => TenantHandleAllocation::STATUS_RETIRED,
                    'is_canonical' => false,
                    'assigned_at' => $tenant->created_at ?? $retiredAt,
                    'retired_at' => $retiredAt,
                    'releasable_at' => $retiredAt->copy()->addDays($quarantineDays),
                    'actor_id' => $actorId,
                    'reason' => 'tenant_soft_deleted',
                ]);
            }

            Domain::query()->where('domain', $handle)->where('tenant_id', $tenant->id)->delete();

            // Clear active address so AVAILABLE can mean allocatable after release.
            $tenant->forceFill(['slug' => null])->saveQuietly();

            \Illuminate\Support\Facades\Cache::forget('tenant_handle_resolve:'.$handle);

            $this->audit->log('tenant_handle.retired', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => Tenant::class,
                'auditable_id' => (string) $tenant->id,
                'old_values' => ['handle' => $handle],
                'new_values' => [
                    'status' => TenantHandleAllocation::STATUS_RETIRED,
                    'reason' => 'tenant_soft_deleted',
                    'actor_id' => $actorId,
                ],
            ]);
        });
    }

    /**
     * Invalidate in-flight address-bound SSO/enrollment state after rename.
     */
    private function invalidateAddressBoundSecurityState(Tenant $tenant, string $oldHandle, string $newHandle): void
    {
        $oldFqdn = strtolower($this->addressing->tenantHostFqdn($oldHandle));
        $newFqdn = strtolower($this->addressing->tenantHostFqdn($newHandle));

        // Open SSO authentication transactions targeting old host → fail closed.
        SsoAuthenticationTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($oldFqdn, $oldHandle): void {
                $q->where('destination_host', $oldFqdn)
                    ->orWhere('destination_host', $oldHandle);
            })
            ->whereNull('consumed_at')
            ->whereNotIn('status', [
                SsoAuthenticationTransaction::STATUS_FAILED,
                SsoAuthenticationTransaction::STATUS_EXPIRED,
                SsoAuthenticationTransaction::STATUS_CONSUMED,
                SsoAuthenticationTransaction::STATUS_SUPERSEDED,
            ])
            ->update([
                'status' => SsoAuthenticationTransaction::STATUS_FAILED,
                'failure_reason' => 'tenant_handle_renamed',
            ]);

        // Pending workforce invitations: retarget host to same Tenant's new address (Tenant-ID-safe).
        if ($this->addressing->isPathHost()) {
            try {
                tenancy()->initialize($tenant);
                WorkforceSsoEnrollmentInvitation::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('tenant_host', $oldFqdn)
                    ->whereNull('consumed_at')
                    ->whereNull('cancelled_at')
                    ->update(['tenant_host' => $newFqdn]);
            } catch (\Throwable) {
                // Tenant DB may be unavailable in some lab modes — release gate will block.
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }
    }
}
