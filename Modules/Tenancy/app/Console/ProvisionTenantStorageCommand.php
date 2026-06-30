<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantOnboardingService;

class ProvisionTenantStorageCommand extends Command
{
    protected $signature = 'tenant:provision-storage {tenant : Tenant UUID}';

    protected $description = 'BK-005: Complete R2 storage provisioning for manual strategy tenants';

    public function handle(TenantOnboardingService $onboarding): int
    {
        $tenant = Tenant::query()->find($this->argument('tenant'));

        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        if ($tenant->type !== 'organization') {
            $this->error('Only organization tenants can be provisioned via this command.');

            return self::FAILURE;
        }

        $result = $onboarding->completeStorageProvisioning($tenant);

        $this->info('Tenant: '.$result->tenant->id);
        $this->table(
            ['Rule', 'Satisfied'],
            [
                ['R1 registry', $result->r1Registry ? 'yes' : 'no'],
                ['R2 storage', $result->r2Storage ? 'yes' : 'no'],
                ['R3 RBAC', $result->r3Rbac ? 'yes' : 'no'],
                ['R4 owner', $result->r4Owner ? 'yes' : 'no'],
                ['R5 owner auth', $result->r5OwnerAuth ? 'yes' : 'no'],
                ['Ready (excl. R6)', $result->r1Registry && $result->r2Storage && $result->r3Rbac && $result->r4Owner && $result->r5OwnerAuth ? 'yes' : 'no'],
            ]
        );

        return $result->r2Storage ? self::SUCCESS : self::FAILURE;
    }
}
