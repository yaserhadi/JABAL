<?php

namespace App\Support\Contracts\Catalog;

/**
 * WAVE-6: Single entitlement evaluation boundary for Product Capability availability.
 * Capability ≠ Permission. Commercial entitlement ≠ authorization.
 */
interface TenantCapabilityEvaluator
{
    public function tenantHasCapability(string $tenantId, string $capabilityCode): bool;

    /**
     * Both must pass where both are required:
     * Tenant entitlement permits capability AND user may perform action (caller supplies permission check).
     */
    public function capabilityAvailableAndAuthorized(
        string $tenantId,
        string $capabilityCode,
        bool $userAuthorizedForAction
    ): bool;
}
