<?php

namespace App\Support\Contracts\Tenancy;

use Modules\Tenancy\Models\Tenant;

/**
 * Resolves how tenant-owned data is stored and accessed for a given tenant.
 *
 * **Strategy (ADR-0007 Appendix A):**
 * - Deployment default: `TENANCY_MODE` in `.env` → `config('tenancy_storage.mode')`.
 * - Per-tenant override: `tenants.isolation_level` (`shared` | `database` | `schema`) combined with mode flags.
 * - Domain and module services depend on this contract (or tenant context after middleware init), not on a
 *   fixed connection name or ad-hoc `where('tenant_id', $id)` filters.
 *
 * **Consumers:** tenant-scoped Eloquent models, migrations targeting the tenant store, persistence layer,
 * and tests. Do not inject raw `tenant_id` filtering into business services.
 *
 * **Stage boundary (JABAL Core Realignment):** Stage 3 = contract + config + resolver binding
 * (`DefaultTenantStorageResolver`). Stage 5 = runtime provisioning for `database_per_tenant` /
 * `schema_per_tenant` (Stancl bootstrappers). See docs/reports/JABAL_CORE_REALIGNMENT.md.
 */
interface TenantStorageResolver
{
    /**
     * Deployment-wide storage mode from `TENANCY_MODE`.
     *
     * @return string `shared_db` | `database_per_tenant` | `schema_per_tenant`
     */
    public function mode(): string;

    /**
     * Laravel database connection name for tenant-owned models for this tenant.
     */
    public function connectionFor(Tenant $tenant): string;

    /**
     * Whether tenant-owned rows in the active store use an explicit `tenant_id` column.
     *
     * `true` for `shared_db` / effective level `shared` (BelongsToTenant applies).
     * `false` when the whole database or schema is tenant-scoped (no row-level tenant_id).
     */
    public function usesExplicitTenantIdColumn(Tenant $tenant): bool;

    /**
     * Resolved isolation level for this tenant after applying `TENANCY_MODE` and tenant metadata.
     *
     * @return string `shared` | `database` | `schema`
     */
    public function effectiveIsolationLevel(Tenant $tenant): string;
}
