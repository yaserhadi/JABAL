<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Run tenant-layer migrations against a physical database (migrations hardcode Schema::connection('tenant')).
 */
class TenantLayerMigrationRunner
{
    public function runMigrations(string $physicalDatabaseName): void
    {
        $this->withPhysicalDatabase($physicalDatabaseName, function (string $sharedConnection): void {
            Artisan::call('migrate', [
                '--database' => $sharedConnection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
        });
    }

    public function migrateFresh(string $physicalDatabaseName): void
    {
        $this->withPhysicalDatabase($physicalDatabaseName, function (string $sharedConnection): void {
            Artisan::call('migrate:fresh', [
                '--database' => $sharedConnection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
        });
    }

    public function ensureMigrated(string $physicalDatabaseName): void
    {
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $original = config("database.connections.{$sharedConnection}.database");

        Config::set("database.connections.{$sharedConnection}.database", $physicalDatabaseName);

        try {
            if (
                ! Schema::connection($sharedConnection)->hasTable('users')
                || ! Schema::connection($sharedConnection)->hasTable('permissions')
            ) {
                $this->migrateFresh($physicalDatabaseName);
            }
        } finally {
            Config::set("database.connections.{$sharedConnection}.database", $original);
        }
    }

    /**
     * @param  callable(string $sharedConnection): void  $callback
     */
    private function withPhysicalDatabase(string $physicalDatabaseName, callable $callback): void
    {
        $sharedConnection = (string) config('tenancy_storage.shared_connection', 'tenant');
        $configKey = "database.connections.{$sharedConnection}";
        $original = config("{$configKey}.database");

        Config::set("{$configKey}.database", $physicalDatabaseName);

        try {
            $callback($sharedConnection);
        } finally {
            Config::set("{$configKey}.database", $original);
        }
    }
}
