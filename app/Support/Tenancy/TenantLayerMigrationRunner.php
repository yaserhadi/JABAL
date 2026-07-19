<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run tenant-layer migrations against a physical database (migrations hardcode Schema::connection('tenant')).
 *
 * Safety: migrateFresh runs only on databases with no established tenant tables (users/permissions).
 * Existing tenant data always receives incremental runMigrations only.
 */
class TenantLayerMigrationRunner
{
    /** @var list<string> */
    private const ESTABLISHED_TABLE_MARKERS = ['users', 'permissions'];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'users',
        'permissions',
        'user_sessions',
        'tenant_security_policies',
        'tenant_sso_config',
        'tenant_sso_config_versions',
        'tenant_user_identities',
    ];

    public function runMigrations(string $physicalDatabaseName): void
    {
        $this->withPhysicalDatabase($physicalDatabaseName, function (string $sharedConnection): void {
            $exitCode = Artisan::call('migrate', [
                '--database' => $sharedConnection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    'Tenant-layer migrate failed (exit '.$exitCode.'): '.Artisan::output()
                );
            }
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
        DB::purge($sharedConnection);

        try {
            $schema = Schema::connection($sharedConnection);

            if (! $this->databaseHasAnyTable($schema)) {
                $this->migrateFresh($physicalDatabaseName);

                return;
            }

            if ($this->hasMissingRequiredTables($schema)) {
                $this->runMigrations($physicalDatabaseName);
            }
        } finally {
            Config::set("database.connections.{$sharedConnection}.database", $original);
            DB::purge($sharedConnection);
        }
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     */
    public function databaseHasAnyTable($schema): bool
    {
        $result = $schema->getConnection()->selectOne(
            "SELECT COUNT(*)::int AS aggregate FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
        );

        return (int) ($result->aggregate ?? 0) > 0;
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     */
    public function hasEstablishedTenantTables($schema): bool
    {
        foreach (self::ESTABLISHED_TABLE_MARKERS as $table) {
            if ($schema->hasTable($table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Database\Schema\Builder  $schema
     */
    public function hasMissingRequiredTables($schema): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! $schema->hasTable($table)) {
                return true;
            }
        }

        return false;
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
        DB::purge($sharedConnection);

        try {
            $callback($sharedConnection);
        } finally {
            Config::set("{$configKey}.database", $original);
            DB::purge($sharedConnection);
        }
    }
}
