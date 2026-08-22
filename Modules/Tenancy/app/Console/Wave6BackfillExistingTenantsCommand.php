<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Billing\Models\Offering;
use Modules\Billing\Services\ProductCatalogService;
use Modules\Tenancy\Models\SetupDefinition;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetupState;
use Modules\Tenancy\Services\LegalOrganizationService;
use Modules\Tenancy\Services\SetupDefinitionCatalog;

/**
 * WAVE-6: Safe backfill — existing tenants remain operationally usable (grandfathered).
 */
class Wave6BackfillExistingTenantsCommand extends Command
{
    protected $signature = 'wave6:backfill-existing-tenants {--dry-run : Report only}';

    protected $description = 'WAVE-6: Grandfather existing tenants (Legal Org + Offering + setup_grandfathered)';

    public function handle(
        ProductCatalogService $catalog,
        LegalOrganizationService $legalOrgs,
        SetupDefinitionCatalog $setupCatalog
    ): int {
        $dry = (bool) $this->option('dry-run');
        $offering = $catalog->ensureDefaultCatalog();
        $setupCatalog->ensureDefaults();

        $tenants = Tenant::query()->whereNull('deleted_at')->get();
        $count = 0;

        foreach ($tenants as $tenant) {
            if ($dry) {
                $this->line("[dry-run] would grandfather tenant {$tenant->id} ({$tenant->slug})");
                $count++;

                continue;
            }

            if (! $tenant->legal_organization_id) {
                $org = $legalOrgs->create($tenant->name.' (legacy)', [
                    'backfill' => true,
                    'source_tenant_id' => $tenant->id,
                ]);
                $tenant->forceFill(['legal_organization_id' => $org->id])->save();
            }

            if (! $tenant->offering_id) {
                $tenant->forceFill(['offering_id' => $offering->id])->save();
            }

            $tenant->forceFill(['setup_grandfathered' => true])->save();

            foreach (SetupDefinition::query()->where('is_active', true)->get() as $definition) {
                TenantSetupState::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'setup_definition_id' => $definition->id,
                    ],
                    [
                        'definition_version' => $definition->version,
                        'status' => TenantSetupState::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'evidence' => ['source' => 'wave6_backfill_grandfather'],
                    ]
                );
            }

            $count++;
        }

        $this->info(($dry ? 'Would process ' : 'Processed ').$count.' tenant(s).');

        return self::SUCCESS;
    }
}
