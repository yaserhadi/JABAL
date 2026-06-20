<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use InvalidArgumentException;
use Modules\Tenancy\Models\Tenant;

class DefaultTenantStorageResolver implements TenantStorageResolver
{
    public function mode(): string
    {
        return (string) config('tenancy_storage.mode', 'shared_db');
    }

    public function connectionFor(Tenant $tenant): string
    {
        $level = $this->effectiveIsolationLevel($tenant);

        return match ($level) {
            'shared' => (string) config('tenancy_storage.shared_connection', 'tenant'),
            'database' => $this->databaseConnectionName($tenant),
            'schema' => (string) config('tenancy_storage.shared_connection', 'tenant'),
            default => throw new InvalidArgumentException("Unsupported isolation level [{$level}]."),
        };
    }

    public function usesExplicitTenantIdColumn(Tenant $tenant): bool
    {
        return $this->effectiveIsolationLevel($tenant) === 'shared';
    }

    public function effectiveIsolationLevel(Tenant $tenant): string
    {
        return match ($this->mode()) {
            'shared_db' => 'shared',
            'database_per_tenant' => $this->resolveDatabasePerTenantLevel($tenant),
            'schema_per_tenant' => $this->resolveSchemaPerTenantLevel($tenant),
            default => $tenant->isolation_level ?? (string) config('tenancy_storage.default_isolation_level', 'shared'),
        };
    }

    protected function resolveDatabasePerTenantLevel(Tenant $tenant): string
    {
        if (! $this->allowsDatabasePerTenant()) {
            return 'shared';
        }

        $config = $this->databaseConfigFor($tenant);

        if (! $config || $config->provisioning_status !== 'active') {
            return 'shared';
        }

        if ($config->isolation_level === 'database') {
            return 'database';
        }

        return 'shared';
    }

    protected function resolveSchemaPerTenantLevel(Tenant $tenant): string
    {
        if ($tenant->isolation_level === 'database' && $this->allowsDatabasePerTenant()) {
            $config = $this->databaseConfigFor($tenant);

            if ($config && $config->provisioning_status === 'active' && $config->isolation_level === 'database') {
                return 'database';
            }
        }

        if ($tenant->isolation_level === 'schema' && $this->allowsSchemaPerTenant()) {
            $config = $this->databaseConfigFor($tenant);

            if ($config && $config->provisioning_status === 'active' && $config->isolation_level === 'schema') {
                return 'schema';
            }
        }

        return 'shared';
    }

    protected function databaseConfigFor(Tenant $tenant): ?\Modules\Tenancy\Models\TenantDatabaseConfig
    {
        if ($tenant->relationLoaded('databaseConfig')) {
            return $tenant->databaseConfig;
        }

        return $tenant->databaseConfig()->first();
    }

    protected function allowsDatabasePerTenant(): bool
    {
        return (bool) config('tenancy_storage.allow_database_per_tenant', true);
    }

    protected function allowsSchemaPerTenant(): bool
    {
        return (bool) config('tenancy_storage.allow_schema_per_tenant', false);
    }

    protected function databaseConnectionName(Tenant $tenant): string
    {
        return 'tenant_db_'.$tenant->getTenantKey();
    }
}
