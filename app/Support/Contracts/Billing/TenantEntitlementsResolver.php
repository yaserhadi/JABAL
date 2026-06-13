<?php

namespace App\Support\Contracts\Billing;

interface TenantEntitlementsResolver
{
    /**
     * @return list<string> Active entitlement codes for a tenant (e.g. mfa_available).
     */
    public function entitlementsForTenant(string $tenantId): array;

    public function tenantHasEntitlement(string $tenantId, string $code): bool;
}
