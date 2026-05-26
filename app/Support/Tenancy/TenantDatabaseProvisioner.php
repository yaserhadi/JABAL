<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Modules\Tenancy\Models\Tenant;

/**
 * Provisions tenant storage for database_per_tenant mode (Phase 6).
 * shared_db: no-op. database_per_tenant: create DB + run tenant migrations (future stancl wiring).
 */
class TenantDatabaseProvisioner
{
    public function __construct(
        private readonly TenantStorageResolver $resolver
    ) {}

    public function provision(Tenant $tenant): void
    {
        $level = $this->resolver->effectiveIsolationLevel($tenant);

        if ($level === 'shared') {
            return;
        }

        if ($level === 'database') {
            // Stancl DatabaseTenancyBootstrapper + dynamic connections land in a follow-up
            // when TENANCY_MODE=database_per_tenant is enabled in production config.
            return;
        }

        if ($level === 'schema') {
            return;
        }
    }
}
