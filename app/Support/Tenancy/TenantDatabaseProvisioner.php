<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Throwable;

/**
 * Provisions tenant storage for database_per_tenant mode (Decision B).
 */
class TenantDatabaseProvisioner
{
    public function __construct(
        private readonly TenantStorageResolver $resolver,
        private readonly TenantConnectionRegistry $connectionRegistry,
        private readonly TenantLayerMigrationRunner $migrationRunner,
    ) {}

    public function provision(Tenant $tenant): void
    {
        $level = $this->resolver->effectiveIsolationLevel($tenant);

        if ($level === 'shared') {
            $tenant->loadMissing('databaseConfig');
            if ($tenant->isolation_level === 'database' && $tenant->databaseConfig) {
                $this->provisionDedicatedDatabase($tenant);
            }

            return;
        }

        if ($level === 'database') {
            $this->provisionDedicatedDatabase($tenant);

            return;
        }

        if ($level === 'schema') {
            return;
        }
    }

    protected function provisionDedicatedDatabase(Tenant $tenant): void
    {
        $tenant->loadMissing('databaseConfig');
        $config = $tenant->databaseConfig;

        if (! $config instanceof TenantDatabaseConfig) {
            throw new \RuntimeException('Tenant ['.$tenant->id.'] missing tenant_database_config row.');
        }

        if ($config->provisioning_status === 'active' && $config->database_name) {
            $this->connectionRegistry->register($tenant);

            return;
        }

        if ($config->provisioning_status === 'failed') {
            throw new \RuntimeException(
                'Tenant ['.$tenant->id.'] provisioning previously failed; manual recovery required.'
            );
        }

        $databaseName = $config->database_name ?? $this->defaultDatabaseName($tenant);

        try {
            if (! $this->databaseExists($databaseName)) {
                $quoted = '"'.str_replace('"', '""', $databaseName).'"';
                DB::connection('central')->statement('CREATE DATABASE '.$quoted);
            }

            $config->update([
                'database_name' => $databaseName,
                'isolation_level' => 'database',
                'provisioning_status' => 'provisioning',
            ]);

            $this->connectionRegistry->register($tenant);

            $this->migrationRunner->runMigrations($databaseName);

            $config->update(['provisioning_status' => 'active']);
        } catch (Throwable $e) {
            $config->update(['provisioning_status' => 'failed']);

            throw $e;
        }
    }

    protected function databaseExists(string $databaseName): bool
    {
        $row = DB::connection('central')->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$databaseName]
        );

        return $row !== null;
    }

    protected function defaultDatabaseName(Tenant $tenant): string
    {
        if (app()->environment('testing')) {
            return (string) config('database.connections.tenant.database');
        }

        return 'jabal_tenant_'.str_replace('-', '', (string) $tenant->getTenantKey());
    }
}
