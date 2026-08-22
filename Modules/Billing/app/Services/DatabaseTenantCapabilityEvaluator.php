<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use App\Support\Contracts\Catalog\TenantCapabilityEvaluator;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-6 SSOT evaluation: Tenant Capability availability via Offering + Billing entitlements.
 * Does not check Spatie permissions (callers combine via capabilityAvailableAndAuthorized).
 */
class DatabaseTenantCapabilityEvaluator implements TenantCapabilityEvaluator
{
    public function __construct(
        private readonly TenantEntitlementsResolver $entitlements
    ) {}

    public function tenantHasCapability(string $tenantId, string $capabilityCode): bool
    {
        $capability = Capability::query()
            ->where('code', $capabilityCode)
            ->where('is_active', true)
            ->first();

        if ($capability === null) {
            return false;
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return false;
        }

        if ($tenant->offering_id) {
            $offering = Offering::query()
                ->with('capabilities')
                ->find($tenant->offering_id);

            if ($offering === null || ! $offering->isPublished()) {
                return false;
            }

            $included = $offering->capabilities
                ->contains(fn (Capability $c) => $c->code === $capabilityCode && (bool) $c->pivot->included);

            if (! $included) {
                return false;
            }
        }

        if ($capability->entitlement_code) {
            return $this->entitlements->tenantHasEntitlement($tenantId, $capability->entitlement_code);
        }

        // No commercial entitlement code: availability is Offering membership (or catalog-only when unassigned).
        return $tenant->offering_id !== null;
    }

    public function capabilityAvailableAndAuthorized(
        string $tenantId,
        string $capabilityCode,
        bool $userAuthorizedForAction
    ): bool {
        return $this->tenantHasCapability($tenantId, $capabilityCode) && $userAuthorizedForAction;
    }
}
