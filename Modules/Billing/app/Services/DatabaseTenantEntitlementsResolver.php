<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Subscription;

class DatabaseTenantEntitlementsResolver implements TenantEntitlementsResolver
{
    public function entitlementsForTenant(string $tenantId): array
    {
        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('plan.entitlements')
            ->first();

        if (! $subscription?->plan) {
            return [];
        }

        return $subscription->plan->entitlements
            ->where('is_active', true)
            ->pluck('code')
            ->values()
            ->all();
    }

    public function tenantHasEntitlement(string $tenantId, string $code): bool
    {
        return in_array($code, $this->entitlementsForTenant($tenantId), true);
    }
}
