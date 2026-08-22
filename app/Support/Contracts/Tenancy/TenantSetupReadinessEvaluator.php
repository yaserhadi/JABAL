<?php

namespace App\Support\Contracts\Tenancy;

/**
 * WAVE-6: Tenant Setup Operational Readiness (≠ tenants.status Active).
 */
interface TenantSetupReadinessEvaluator
{
    public function isOperationallyReady(string $tenantId): bool;

    /**
     * @return array{ready: bool, blocking_incomplete: list<string>, optional_incomplete: list<string>, applicable: list<array>}
     */
    public function evaluate(string $tenantId): array;
}
