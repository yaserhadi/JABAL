<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Modules\Tenancy\Models\Tenant;

class TenantConnectionRegistry
{
    public function register(Tenant $tenant): string
    {
        $resolver = app(TenantStorageResolver::class);
        $connectionName = $resolver->connectionFor($tenant);
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');

        if ($connectionName === $sharedConnection) {
            return $connectionName;
        }

        if (config("database.connections.{$connectionName}")) {
            return $connectionName;
        }

        $tenant->loadMissing('databaseConfig');
        $databaseName = $tenant->databaseConfig?->database_name;

        if (! $databaseName) {
            throw new InvalidArgumentException(
                'Tenant ['.$tenant->id.'] requires database_name in tenant_database_config.'
            );
        }

        $template = config('database.connections.tenant');

        if (! is_array($template)) {
            throw new InvalidArgumentException('Missing database.connections.tenant template.');
        }

        Config::set("database.connections.{$connectionName}", array_merge($template, [
            'database' => $databaseName,
        ]));

        return $connectionName;
    }

    public function forget(Tenant $tenant): void
    {
        $connectionName = 'tenant_db_'.$tenant->getTenantKey();

        if (config("database.connections.{$connectionName}")) {
            Config::set("database.connections.{$connectionName}", null);
        }
    }
}
