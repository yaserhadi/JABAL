<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoEnforcementException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Auth\SsoUserReadinessState;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5: Population readiness accounting.
 * Linked alone / invitation / enrollment started ≠ Ready.
 */
class SsoReadinessAccountingService
{
    public function __construct(
        protected SsoIdentityLifecycle $lifecycle,
        protected SsoConfigService $configService,
    ) {}

    /**
     * @return array{state: string, reason: string|null}
     */
    public function classifyUser(Tenant $tenant, TenantUser $user): array
    {
        return $this->withTenant($tenant, function () use ($tenant, $user) {
            $membership = Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $membership || ! in_array((string) $membership->status, ['active'], true)) {
                return ['state' => SsoUserReadinessState::INELIGIBLE, 'reason' => 'membership_not_active'];
            }

            if ($user->trashed()) {
                return ['state' => SsoUserReadinessState::INELIGIBLE, 'reason' => 'user_trashed'];
            }

            $activeVersionId = $this->configService->getActiveVersionId($tenant);
            $current = TenantUserIdentity::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('binding_role', TenantUserIdentity::ROLE_CURRENT)
                ->first();

            if ($current instanceof TenantUserIdentity
                && $this->lifecycle->isEffectivelyReady($current, $user, $activeVersionId)
            ) {
                return ['state' => SsoUserReadinessState::READY, 'reason' => null];
            }

            if ($this->hasValidException($tenant, $user)) {
                return ['state' => SsoUserReadinessState::EXCEPTION, 'reason' => 'valid_enforcement_exception'];
            }

            return ['state' => SsoUserReadinessState::NOT_READY, 'reason' => 'not_sso_ready'];
        });
    }

    public function hasValidException(Tenant $tenant, TenantUser $user): bool
    {
        return $this->withTenant($tenant, function () use ($tenant, $user) {
            $exception = SsoEnforcementException::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('status', SsoEnforcementException::STATUS_ACTIVE)
                ->orderByDesc('created_at')
                ->first();

            if (! $exception) {
                return false;
            }

            if ($exception->expires_at !== null && $exception->expires_at->isPast()) {
                $exception->forceFill([
                    'status' => SsoEnforcementException::STATUS_EXPIRED,
                    'closed_at' => now(),
                    'close_reason' => 'expired',
                ])->save();

                return false;
            }

            return $exception->isCurrentlyValid();
        });
    }

    /**
     * Active memberships that are applicable for SSO-only enforcement accounting.
     *
     * @return list<array{user_id: string, state: string, reason: string|null}>
     */
    public function summarizePopulation(Tenant $tenant): array
    {
        $rows = [];
        $memberships = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('user_id')
            ->get();

        foreach ($memberships as $membership) {
            $user = TenantUser::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereKey($membership->user_id)
                ->first();

            if (! $user) {
                $rows[] = [
                    'user_id' => (string) $membership->user_id,
                    'state' => SsoUserReadinessState::INELIGIBLE,
                    'reason' => 'user_missing',
                ];
                continue;
            }

            $classified = $this->classifyUser($tenant, $user);
            $rows[] = [
                'user_id' => (string) $user->id,
                'state' => $classified['state'],
                'reason' => $classified['reason'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{ready: int, exception: int, not_ready: int, ineligible: int, total_active: int}
     */
    public function counts(Tenant $tenant): array
    {
        $summary = $this->summarizePopulation($tenant);
        $counts = [
            'ready' => 0,
            'exception' => 0,
            'not_ready' => 0,
            'ineligible' => 0,
            'total_active' => 0,
        ];

        foreach ($summary as $row) {
            if ($row['state'] === SsoUserReadinessState::INELIGIBLE) {
                $counts['ineligible']++;
                continue;
            }
            $counts['total_active']++;
            match ($row['state']) {
                SsoUserReadinessState::READY => $counts['ready']++,
                SsoUserReadinessState::EXCEPTION => $counts['exception']++,
                default => $counts['not_ready']++,
            };
        }

        return $counts;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withTenant(Tenant $tenant, callable $callback)
    {
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
