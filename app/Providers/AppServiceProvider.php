<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Http\Middleware\ValidateTenantToken;
use App\Listeners\SetSpatiePermissionsTeamId;
use App\Models\TenantPersonalAccessToken;
use App\Support\Context\ActorContext;
use App\Support\Context\ExecutionContext;
use App\Support\Context\RequestContext;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use App\Support\Tenancy\DefaultTenantStorageResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        Auth::provider('tenant_aware_eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

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

        RateLimiter::for('invitations', function (Request $request) {
            $config = config('tenancy.invitation_rate_limit', ['max_attempts' => 10, 'decay_minutes' => 1]);

            return Limit::perMinutes(
                (int) ($config['decay_minutes'] ?? 1),
                (int) ($config['max_attempts'] ?? 10)
            )->by($request->ip());
        });

        // API pipeline: ValidateTenantToken before Sanctum on tenant API routes only.
        // Do NOT prepend Authenticate globally — it runs before StartSession and breaks web session guards.
        $this->app->booted(static function (): void {
            $kernel = app(Kernel::class);
            $kernel->prependToMiddlewarePriority(ValidateTenantToken::class);
            // Sanctum must resolve the tokenable user before Stancl initializes tenancy from X-Tenant-Id,
            // otherwise BelongsToTenant scopes the user query to the header tenant and cross-tenant tokens 401.
            $kernel->appendToMiddlewarePriority(
                \Illuminate\Auth\Middleware\Authenticate::class,
                InitializeTenancyByRequestData::class,
            );
        });
    }
}
