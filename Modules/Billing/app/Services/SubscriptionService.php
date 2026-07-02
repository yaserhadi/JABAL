<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Billing\TenantSubscriptionProvisioner;
use Illuminate\Support\Str;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionPlanChanged;
use Modules\Billing\Events\SubscriptionReactivated;
use Modules\Billing\Events\SubscriptionSuspended;
use Modules\Billing\Exceptions\InvalidSubscriptionTransitionException;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;

/**
 * Commercial subscription orchestration (DEC-0013). All status mutations flow here.
 */
class SubscriptionService implements TenantSubscriptionProvisioner
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        Subscription::STATUS_ACTIVE => [
            Subscription::STATUS_SUSPENDED,
            Subscription::STATUS_CANCELED,
        ],
        Subscription::STATUS_SUSPENDED => [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_CANCELED,
        ],
        Subscription::STATUS_CANCELED => [],
    ];

    public function findForTenant(string $tenantId): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenantId)
            ->with('plan')
            ->orderByDesc('created_at')
            ->first();
    }

    public function findActiveForTenant(string $tenantId): ?Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->with('plan')
            ->first();
    }

    public function ensureDefaultSubscription(string $tenantId, ?string $planCode = null): void
    {
        $planCode ??= Plan::DEFAULT_CODE;

        $active = $this->findActiveForTenant($tenantId);
        if ($active) {
            return;
        }

        $this->createActiveSubscription($tenantId, $planCode);
    }

    public function changePlan(string $tenantId, string $planCode): Subscription
    {
        $subscription = $this->requireMutableSubscription($tenantId);
        $plan = $this->resolveActivePlan($planCode);

        $previousPlanCode = $subscription->plan?->code;

        if ($subscription->plan_id === $plan->id) {
            return $subscription;
        }

        $subscription->plan_id = $plan->id;
        $subscription->save();
        $subscription->load('plan');

        SubscriptionPlanChanged::dispatch(
            $tenantId,
            $subscription->id,
            $previousPlanCode,
            $plan->code,
        );

        return $subscription;
    }

    public function updateSeatLimit(string $tenantId, ?int $seatLimit): Subscription
    {
        $subscription = $this->requireMutableSubscription($tenantId);
        $subscription->seat_limit = $seatLimit;
        $subscription->save();

        return $subscription->load('plan');
    }

    public function suspend(string $tenantId): Subscription
    {
        $subscription = $this->requireSubscription($tenantId);
        $this->assertTransitionAllowed($subscription->status, Subscription::STATUS_SUSPENDED);

        $subscription->status = Subscription::STATUS_SUSPENDED;
        $subscription->save();
        $subscription->load('plan');

        SubscriptionSuspended::dispatch(
            $tenantId,
            $subscription->id,
            $subscription->plan->code,
        );

        return $subscription;
    }

    public function reactivate(string $tenantId): Subscription
    {
        $subscription = $this->requireSubscription($tenantId);
        $this->assertTransitionAllowed($subscription->status, Subscription::STATUS_ACTIVE);

        $subscription->status = Subscription::STATUS_ACTIVE;
        $subscription->save();
        $subscription->load('plan');

        SubscriptionReactivated::dispatch(
            $tenantId,
            $subscription->id,
            $subscription->plan->code,
        );

        return $subscription;
    }

    public function cancel(string $tenantId): Subscription
    {
        $subscription = $this->requireSubscription($tenantId);
        $this->assertTransitionAllowed($subscription->status, Subscription::STATUS_CANCELED);

        $subscription->status = Subscription::STATUS_CANCELED;
        $subscription->ends_at = now();
        $subscription->save();
        $subscription->load('plan');

        SubscriptionCancelled::dispatch(
            $tenantId,
            $subscription->id,
            $subscription->plan->code,
        );

        return $subscription;
    }

    protected function createActiveSubscription(string $tenantId, string $planCode): Subscription
    {
        $plan = $this->resolveActivePlan($planCode);

        $subscription = Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $subscription->load('plan');

        SubscriptionCreated::dispatch(
            $tenantId,
            $subscription->id,
            $plan->code,
        );

        return $subscription;
    }

    protected function requireSubscription(string $tenantId): Subscription
    {
        $subscription = $this->findForTenant($tenantId);

        if (! $subscription) {
            throw new \RuntimeException("No subscription found for tenant [{$tenantId}].");
        }

        return $subscription;
    }

    protected function requireMutableSubscription(string $tenantId): Subscription
    {
        $subscription = $this->requireSubscription($tenantId);

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            throw new InvalidSubscriptionTransitionException(
                $subscription->status,
                Subscription::STATUS_ACTIVE,
            );
        }

        return $subscription;
    }

    protected function resolveActivePlan(string $planCode): Plan
    {
        $plan = Plan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            throw new \InvalidArgumentException("Active plan [{$planCode}] not found.");
        }

        return $plan;
    }

    protected function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new InvalidSubscriptionTransitionException($fromStatus, $toStatus);
        }
    }
}
