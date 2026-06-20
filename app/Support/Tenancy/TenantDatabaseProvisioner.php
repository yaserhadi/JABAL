<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;

/**
 * Provisions tenant storage for database_per_tenant mode (Decision B).
 */
class TenantDatabaseProvisioner
{
    public function __construct(
        private readonly TenantStorageResolver $resolver,
        private readonly TenantConnectionRegistry $connectionRegistry
    ) {}

    public function provision(Tenant $tenant): void
    {
        $level = $this->resolver->effectiveIsolationLevel($tenant);

        if ($level === 'shared') {
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

        $databaseName = $config->database_name ?? $this->defaultDatabaseName($tenant);

        DB::connection('central')->statement(
            'CREATE DATABASE '.DB::getQueryGrammar()->wrapValue($databaseName)
        );

        $config->update([
            'database_name' => $databaseName,
            'isolation_level' => 'database',
            'provisioning_status' => 'provisioning',
        ]);

        $this->connectionRegistry->register($tenant);

        Artisan::call('migrate', [
            '--database' => $this->resolver->connectionFor($tenant),
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $config->update(['provisioning_status' => 'active']);
    }

    protected function defaultDatabaseName(Tenant $tenant): string
    {
        return 'jabal_tenant_'.str_replace('-', '', (string) $tenant->getTenantKey());
    }
}
