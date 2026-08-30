<?php

namespace Modules\Billing\Providers;

use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use App\Support\Contracts\Billing\TenantSeatLimitResolver;
use App\Support\Contracts\Billing\TenantSubscriptionProvisioner;
use App\Support\Contracts\Catalog\TenantCapabilityEvaluator;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Console\BootstrapSubscriptionsCommand;
use Modules\Billing\Services\DatabaseTenantCapabilityEvaluator;
use Modules\Billing\Services\DatabaseTenantEntitlementsResolver;
use Modules\Billing\Services\DatabaseTenantSeatLimitResolver;
use Modules\Billing\Services\ProductCatalogService;
use Modules\Billing\Services\SubscriptionService;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantEntitlementsResolver::class, DatabaseTenantEntitlementsResolver::class);
        $this->app->singleton(TenantSeatLimitResolver::class, DatabaseTenantSeatLimitResolver::class);
        $this->app->singleton(TenantCapabilityEvaluator::class, DatabaseTenantCapabilityEvaluator::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(ProductCatalogService::class);
        $this->app->singleton(\Modules\Billing\Services\OfferingPublishGate::class);
        $this->app->singleton(TenantSubscriptionProvisioner::class, SubscriptionService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('Billing', 'database/migrations'));
        $this->commands([
            BootstrapSubscriptionsCommand::class,
        ]);
    }
}
