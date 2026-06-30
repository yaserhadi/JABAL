<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Services\TenantOnboardingService;

class OnboardOrganizationTenantCommand extends Command
{
    protected $signature = 'tenant:onboard-organization
                            {--organization-name= : Organization display name}
                            {--owner-name= : Owner display name}
                            {--owner-email= : Owner login email}
                            {--owner-password= : Owner password (min 8 chars)}
                            {--isolation-level=shared : shared, database, or schema}
                            {--slug= : Optional tenant slug}';

    protected $description = 'BK-005: Provision an organization tenant via TenantOnboardingService';

    public function handle(TenantOnboardingService $onboarding): int
    {
        $organizationName = $this->option('organization-name') ?? $this->ask('Organization name');
        $ownerName = $this->option('owner-name') ?? $this->ask('Owner name');
        $ownerEmail = $this->option('owner-email') ?? $this->ask('Owner email');
        $ownerPassword = $this->option('owner-password') ?? $this->secret('Owner password');

        if (! is_string($organizationName) || ! is_string($ownerName) || ! is_string($ownerEmail) || ! is_string($ownerPassword)) {
            $this->error('All owner and organization fields are required.');

            return self::FAILURE;
        }

        $input = new TenantOnboardingInput(
            organizationName: $organizationName,
            ownerName: $ownerName,
            ownerEmail: $ownerEmail,
            ownerPassword: $ownerPassword,
            isolationLevel: (string) ($this->option('isolation-level') ?? 'shared'),
            slug: $this->option('slug') ? (string) $this->option('slug') : null,
        );

        try {
            $result = $onboarding->onboardOrganizationTenant($input);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Tenant: '.$result->tenant->id);
        $this->table(
            ['Rule', 'Satisfied'],
            [
                ['R1 registry', $result->r1Registry ? 'yes' : 'no'],
                ['R2 storage', $result->r2Storage ? 'yes' : 'no'],
                ['R3 RBAC', $result->r3Rbac ? 'yes' : 'no'],
                ['R4 owner', $result->r4Owner ? 'yes' : 'no'],
                ['R5 owner auth', $result->r5OwnerAuth ? 'yes' : 'no'],
                ['Ready', $result->isReady() ? 'yes' : 'no'],
            ]
        );

        if (! $result->isReady() && ! $result->r2Storage) {
            $this->warn('Storage pending — run: php artisan tenant:provision-storage '.$result->tenant->id);
        }

        return $onboarding->isProvisioningComplete($result)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
