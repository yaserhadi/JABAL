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
        $tenantLevel = $tenant->isolation_level;

        return match ($this->mode()) {
            'shared_db' => 'shared',
            'database_per_tenant' => $tenantLevel === 'database' && $this->allowsDatabasePerTenant()
                ? 'database'
                : 'shared',
            'schema_per_tenant' => $tenantLevel === 'schema' && $this->allowsSchemaPerTenant()
                ? 'schema'
                : ($tenantLevel === 'database' && $this->allowsDatabasePerTenant() ? 'database' : 'shared'),
            default => $tenantLevel ?? (string) config('tenancy_storage.default_isolation_level', 'shared'),
        };
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
        $name = $tenant->tenancy_db_name ?? null;

        if (! $name) {
            throw new InvalidArgumentException(
                'Tenant ['.$tenant->id.'] has isolation_level=database but no tenancy_db_name configured.'
            );
        }

        return (string) $name;
    }
}
