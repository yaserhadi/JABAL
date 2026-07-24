<?php

namespace Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-098 readiness: purge disposable demo Enterprise SSO rows (legacy encrypted).
 *
 * Does NOT migrate ciphertext into local_sealed. Delete and reseed instead.
 * Refuses to run against testing DBs unless --allow-testing is passed.
 */
class PurgeLegacySsoDemoCredentialsCommand extends Command
{
    protected $signature = 'identity:sso-purge-legacy-demo
                            {--force : Required confirmation flag}
                            {--all : Delete all tenant SSO configs (not only legacy_encrypted)}
                            {--allow-testing : Allow running against *_testing databases}';

    protected $description = 'Delete disposable demo Enterprise SSO configs using legacy_encrypted (or all with --all)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force.');

            return self::FAILURE;
        }

        $central = (string) config('database.connections.central.database', '');
        $tenantDb = (string) config('database.connections.tenant.database', '');
        if (! $this->option('allow-testing')
            && (str_ends_with($central, '_testing') || str_ends_with($tenantDb, '_testing'))) {
            $this->error('Refusing to purge testing databases without --allow-testing.');

            return self::FAILURE;
        }

        $tenants = Tenant::query()->orderBy('id')->get();
        $deletedConfigs = 0;
        $deletedVersions = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);
            try {
                $versionQuery = DB::connection('tenant')->table('tenant_sso_config_versions');
                $configQuery = DB::connection('tenant')->table('tenant_sso_config');

                if (! $this->option('all')) {
                    $legacyVersionIds = (clone $versionQuery)
                        ->where('credential_source', 'legacy_encrypted')
                        ->pluck('id')
                        ->all();
                    $legacyConfigIds = (clone $versionQuery)
                        ->where('credential_source', 'legacy_encrypted')
                        ->distinct()
                        ->pluck('config_id')
                        ->all();

                    if ($legacyVersionIds !== []) {
                        $deletedVersions += (clone $versionQuery)->whereIn('id', $legacyVersionIds)->delete();
                    }

                    // Drop parent configs that no longer have any versions, or whose only versions were legacy.
                    foreach ($legacyConfigIds as $configId) {
                        $remaining = DB::connection('tenant')->table('tenant_sso_config_versions')
                            ->where('config_id', $configId)
                            ->count();
                        if ($remaining === 0) {
                            $deletedConfigs += DB::connection('tenant')->table('tenant_sso_config')
                                ->where('id', $configId)
                                ->delete();
                        } else {
                            DB::connection('tenant')->table('tenant_sso_config')
                                ->where('id', $configId)
                                ->whereIn('active_version_id', $legacyVersionIds)
                                ->update([
                                    'active_version_id' => null,
                                    'enabled' => false,
                                    'client_secret_encrypted' => null,
                                    'updated_at' => now(),
                                ]);
                        }
                    }

                    // Also clear orphan parent ciphertext (non-authoritative).
                    DB::connection('tenant')->table('tenant_sso_config')
                        ->whereNotNull('client_secret_encrypted')
                        ->update(['client_secret_encrypted' => null, 'updated_at' => now()]);
                } else {
                    $deletedVersions += $versionQuery->delete();
                    $deletedConfigs += $configQuery->delete();
                }
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Purged demo SSO data: versions={$deletedVersions} configs={$deletedConfigs}");
        $this->warn('Reseed via SsoConfigService::update (reference/local_sealed) — do not restore ciphertext.');

        return self::SUCCESS;
    }
}
