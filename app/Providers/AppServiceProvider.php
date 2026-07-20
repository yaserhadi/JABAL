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
use App\Support\Tenancy\TenantAddressingProfile;
use App\Support\Tenancy\TenantRouteRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantStorageResolver::class, DefaultTenantStorageResolver::class);
        $this->app->singleton(TenantAddressingProfile::class);
        $this->app->singleton(TenantRouteRegistrar::class);

        $this->app->singleton(RequestContext::class, function () {
            return RequestContext::getInstance();
        });

        $this->app->singleton(ActorContext::class, function () {
            return ActorContext::getInstance();
        });

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

        // BK-073: fail-fast addressing validation + sync Stancl central_domains.
        $addressing = $this->app->make(TenantAddressingProfile::class);
        $addressing->assertValidConfiguration();
        config([
            'tenancy.central_domains' => array_values(array_unique(array_merge(
                (array) config('tenancy.central_domains', []),
                $addressing->centralHosts(),
            ))),
        ]);

        InitializeTenancyByRequestData::$header = 'X-Tenant-Id';

        InitializeTenancyByRequestData::$onFail = function ($exception, $request, $next) {
            return response()->json([
                'success' => false,
                'error' => 'X-Tenant-Id header required',
            ], 401);
        };

        // BK-073: unknown / unregistered Tenant Host → controlled 404 (fail closed).
        $hostOnFail = static function ($exception, $request, $next) {
            abort(404, 'Tenant not found for host.');
        };
        InitializeTenancyByDomain::$onFail = $hostOnFail;
        InitializeTenancyBySubdomain::$onFail = $hostOnFail;

        Event::subscribe(SetSpatiePermissionsTeamId::class);

        RateLimiter::for('invitations', function (Request $request) {
            $config = config('tenancy.invitation_rate_limit', ['max_attempts' => 10, 'decay_minutes' => 1]);

            return Limit::perMinutes(
                (int) ($config['decay_minutes'] ?? 1),
                (int) ($config['max_attempts'] ?? 10)
            )->by($request->ip());
        });

        RateLimiter::for('api-token-grant', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by(strtolower($request->ip()).'|'.$email);
        });

        RateLimiter::for('tenant-handle-availability', function (Request $request) {
            $userId = (string) ($request->user('platform')?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute(30)->by($userId.'|'.$request->ip());
        });

        // BK-082 WS7: stage-specific Enterprise SSO abuse controls (multi-dimensional; IP alone not sole authority).
        RateLimiter::for('sso-enterprise-start', function (Request $request) {
            $host = strtolower((string) $request->getHost());

            return [
                Limit::perMinute(30)->by('sso-start|'.$host.'|'.$request->ip()),
                Limit::perMinute(120)->by('sso-start|'.$host),
            ];
        });
        RateLimiter::for('sso-enterprise-initiate', function (Request $request) {
            $ref = substr((string) $request->query('t', ''), 0, 64);

            return [
                Limit::perMinute(30)->by('sso-init|'.$request->ip().'|'.$ref),
                Limit::perMinute(120)->by('sso-init|'.$request->ip()),
            ];
        });
        RateLimiter::for('sso-enterprise-callback', function (Request $request) {
            return [
                Limit::perMinute(40)->by('sso-cb|'.$request->ip()),
                Limit::perMinute(20)->by('sso-cb|'.substr((string) $request->input('state', $request->query('state', '')), 0, 64)),
            ];
        });
        RateLimiter::for('sso-enterprise-handoff', function (Request $request) {
            $host = strtolower((string) $request->getHost());

            return [
                Limit::perMinute(30)->by('sso-ho|'.$host.'|'.$request->ip()),
                Limit::perMinute(20)->by('sso-ho|'.substr((string) $request->query('h', ''), 0, 64)),
            ];
        });
        RateLimiter::for('sso-enterprise-mfa', function (Request $request) {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute(20)->by('sso-mfa|'.$userId.'|'.$request->ip());
        });
        RateLimiter::for('sso-enterprise-bclogout', function (Request $request) {
            $tenant = substr((string) $request->query('tenant', $request->input('tenant', '')), 0, 64);

            return [
                Limit::perMinute(60)->by('sso-bc|'.$tenant.'|'.$request->ip()),
                Limit::perMinute(180)->by('sso-bc|'.$tenant),
            ];
        });

        $this->app->booted(static function (): void {
            $kernel = app(Kernel::class);
            $kernel->prependToMiddlewarePriority(ValidateTenantToken::class);
            $kernel->appendToMiddlewarePriority(
                \Illuminate\Auth\Middleware\Authenticate::class,
                InitializeTenancyByRequestData::class,
            );
        });
    }
}
