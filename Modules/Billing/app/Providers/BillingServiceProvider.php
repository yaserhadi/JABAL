<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Billing\Services\DatabaseTenantEntitlementsResolver;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Support\Contracts\Billing\TenantEntitlementsResolver::class,
            DatabaseTenantEntitlementsResolver::class
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Billing', 'database/migrations'));
    }
}
