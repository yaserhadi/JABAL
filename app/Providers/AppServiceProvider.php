<?php

namespace App\Providers;

use App\Listeners\SetSpatiePermissionsTeamId;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use App\Support\Context\ActorContext;
use App\Support\Tenancy\DefaultTenantStorageResolver;
use App\Support\Context\ExecutionContext;
use App\Support\Context\RequestContext;
use App\Http\Middleware\ValidateTenantToken;
use App\Models\TenantPersonalAccessToken;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantStorageResolver::class, DefaultTenantStorageResolver::class);

        // Register RequestContext as singleton
        $this->app->singleton(RequestContext::class, function () {
            return RequestContext::getInstance();
        });

        // Register ActorContext as singleton
        $this->app->singleton(ActorContext::class, function () {
            return ActorContext::getInstance();
        });

        // Register ExecutionContext as singleton
        $this->app->singleton(ExecutionContext::class, function () {
            return ExecutionContext::getInstance();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(TenantPersonalAccessToken::class);

        // PHASE 2: Configure Stancl middleware to use X-Tenant-Id header
        InitializeTenancyByRequestData::$header = 'X-Tenant-Id';

        // PHASE 2: Handle missing/invalid tenant gracefully
        InitializeTenancyByRequestData::$onFail = function ($exception, $request, $next) {
            return response()->json([
                'success' => false,
                'error' => 'X-Tenant-Id header required',
            ], 401);
        };

        // Phase 3B: Set Spatie permissions team context during tenancy initialization
        Event::subscribe(SetSpatiePermissionsTeamId::class);

        // API pipeline: ValidateTenantToken → Sanctum → InitializeTenancyByRequestData
        // (Stancl prepends tenancy middleware globally; re-order so auth runs before tenancy init)
        $this->app->booted(static function (): void {
            $kernel = app(Kernel::class);
            $kernel->prependToMiddlewarePriority(Authenticate::class);
            $kernel->prependToMiddlewarePriority(ValidateTenantToken::class);
        });
    }
}
