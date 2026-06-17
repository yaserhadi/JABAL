<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Billing\TenantSeatLimitResolver;
use Modules\Billing\Models\Subscription;

class DatabaseTenantSeatLimitResolver implements TenantSeatLimitResolver
{
    public function seatLimitForTenant(string $tenantId): ?int
    {
        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('plan')
            ->first();

        if (! $subscription) {
            return null;
        }

        if ($subscription->seat_limit !== null) {
            return (int) $subscription->seat_limit;
        }

        return $subscription->plan?->seat_limit;
    }
}
