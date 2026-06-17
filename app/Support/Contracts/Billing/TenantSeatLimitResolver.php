<?php

namespace App\Support\Contracts\Billing;

interface TenantSeatLimitResolver
{
    /**
     * Effective seat limit for a tenant (subscription override, then plan).
     * null = unlimited.
     */
    public function seatLimitForTenant(string $tenantId): ?int;
}
