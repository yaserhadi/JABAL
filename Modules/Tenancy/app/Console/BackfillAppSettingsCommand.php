<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantAppSettingsBackfill;

class BackfillAppSettingsCommand extends Command
{
    protected $signature = 'tenant:backfill-app-settings
                            {--tenant= : UUID of a single tenant to backfill}
                            {--verify : Verify per-tenant parity after backfill}';

    protected $description = 'BK-028: Backfill tenant app_settings from central tenant_settings (idempotent)';

    public function handle(TenantAppSettingsBackfill $backfill): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                $this->error('Tenant not found: '.$tenantId);

                return self::FAILURE;
            }

            $result = $backfill->backfillTenant($tenant);
            $this->info("Tenant {$tenantId}: {$result}");

            if ($this->option('verify')) {
                $parity = $backfill->verifyTenantParity($tenant);
                if (! $parity['ok']) {
                    $this->error('Parity failed: '.implode(', ', $parity['mismatches']));

                    return self::FAILURE;
                }
                $this->info('Parity OK');
            }

            return self::SUCCESS;
        }

        $stats = $backfill->backfillAll();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Tenants processed', $stats['tenants']],
                ['Backfilled', $stats['backfilled']],
                ['Skipped', $stats['skipped']],
                ['Errors', count($stats['errors'])],
            ]
        );

        foreach ($stats['errors'] as $error) {
            $this->error($error);
        }

        if ($stats['errors'] !== []) {
            return self::FAILURE;
        }

        if ($this->option('verify')) {
            $failures = 0;
            Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($backfill, &$failures) {
                $parity = $backfill->verifyTenantParity($tenant);
                if (! $parity['ok']) {
                    $failures++;
                    $this->error("Parity failed for {$tenant->id}: ".implode(', ', $parity['mismatches']));
                }
            });

            if ($failures > 0) {
                return self::FAILURE;
            }
            $this->info('All tenants passed parity verification.');
        }

        return self::SUCCESS;
    }
}
