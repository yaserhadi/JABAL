<?php

namespace Modules\Identity\Support;

use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use Modules\Tenancy\Models\Tenant;

/** Gates security features via Billing contract only — no Billing internals. */
class SecurityFeatureGate
{
    public function __construct(
        protected TenantEntitlementsResolver $entitlements
    ) {}

    public function featureEnabled(Tenant $tenant, string $feature): bool
    {
        return $this->entitlements->tenantHasEntitlement($tenant->getTenantKey(), $feature);
    }

    public function isSsoAvailable(Tenant $tenant): bool
    {
        return $this->featureEnabled($tenant, 'sso_available');
    }
}
