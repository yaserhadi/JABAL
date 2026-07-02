<?php

namespace App\Support\Contracts\Billing;

/**
 * Cross-module hook for ensuring a tenant has a commercial subscription (DEC-0013).
 */
interface TenantSubscriptionProvisioner
{
    public function ensureDefaultSubscription(string $tenantId, ?string $planCode = null): void;
}
