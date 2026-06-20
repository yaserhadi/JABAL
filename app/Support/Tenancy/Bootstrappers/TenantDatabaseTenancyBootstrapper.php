<?php

namespace App\Support\Tenancy\Bootstrappers;

use App\Support\Tenancy\TenantConnectionRegistry;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class TenantDatabaseTenancyBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        private readonly TenantConnectionRegistry $registry
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->registry->register($tenant);
    }

    public function revert(): void
    {
        $tenant = tenancy()->tenant;

        if ($tenant) {
            $this->registry->forget($tenant);
        }
    }
}
