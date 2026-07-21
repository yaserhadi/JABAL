<?php

use App\Exceptions\DomainException;
use App\Http\Auth\AuthenticationRedirects;
use App\Http\Middleware\ConfigureApplicationRuntime;
use App\Http\Middleware\ConfigureTenantSessionConnection;
use App\Http\Middleware\EnsureResolvedTenantIsOperational;
use App\Http\Middleware\InitializeTenancyByHostWhenApplicable;
use App\Http\Middleware\InitializeTenancyByPathWhenApplicable;
use App\Http\Middleware\InitializeTenancyFromAuthRequest;
use App\Http\Middleware\InitializeTenancyFromSession;
use App\Http\Middleware\InitializeTenancyFromSsoState;
use App\Http\Middleware\RejectTenancyContextConflict;
use App\Http\Middleware\RequestHostClassifier;
use App\Support\Context\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../Modules/Api/routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            $addressing = app(\App\Support\Tenancy\TenantAddressingProfile::class);
            if ($addressing->isHost() && $addressing->platformHost() !== '') {
                Route::middleware('web')
                    ->domain($addressing->platformHost())
                    ->group(base_path('routes/platform.php'));
            } else {
                Route::middleware('web')
                    ->group(base_path('routes/platform.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // BK-073: TrustProxies baseline — read env here (config may not be ready yet).
        $trustedProxiesRaw = (string) env('TENANCY_TRUSTED_PROXIES', '');
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxiesRaw))));
        if ($trustedProxies !== [] && ! in_array('*', $trustedProxies, true)) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_AWS_ELB
            );
        }

        // BK-073: TrustHosts when Host profile — env-only (config:cache-safe values live in config file for runtime).
        $profile = strtolower(trim((string) env('TENANCY_ADDRESSING_PROFILE', 'path')));
        $platformBaseDomain = strtolower(trim((string) env('TENANT_PLATFORM_BASE_DOMAIN', '')));
        if ($profile === 'host' && $platformBaseDomain !== '') {
            $base = preg_quote($platformBaseDomain, '/');
            $centralRaw = (string) env('TENANCY_CENTRAL_HOSTS', 'localhost,127.0.0.1');
            $central = array_values(array_filter(array_map(
                static fn (string $h): string => strtolower(trim($h)),
                explode(',', $centralRaw)
            )));
            foreach ([
                env('TENANCY_PLATFORM_HOST'),
                env('TENANCY_AUTH_HOST'),
                env('TENANCY_API_HOST'),
                $platformBaseDomain,
            ] as $extra) {
                $extra = strtolower(trim((string) $extra));
                if ($extra !== '' && ! in_array($extra, $central, true)) {
                    $central[] = $extra;
                }
            }
            $middleware->trustHosts(at: function () use ($base, $central) {
                $hosts = array_map(
                    static fn (string $h): string => '^'.preg_quote($h, '/').'$',
                    $central
                );
                $hosts[] = '^[a-z0-9]([a-z0-9-]*[a-z0-9])?\.'.$base.'$';

                return $hosts;
            }, subdomains: false);
        }

        $middleware->web([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
        ]);

        /*
         * BK-073 Phase 1 (before StartSession): classify → resolve → operational gate → runtime → session connection.
         * InitializeTenancyFromAuthRequest / FromSsoState remain pre-session for Path database_per_tenant
         * deferred session binding; Host mode makes them validate-only / no-op for initialize().
         */
        $middleware->prependToGroup('web', [
            RequestHostClassifier::class,
            InitializeTenancyByHostWhenApplicable::class,
            InitializeTenancyByPathWhenApplicable::class,
            EnsureResolvedTenantIsOperational::class,
            ConfigureApplicationRuntime::class,
            InitializeTenancyFromAuthRequest::class,
            InitializeTenancyFromSsoState::class,
            ConfigureTenantSessionConnection::class,
        ]);

        // Phase 2 (after StartSession): validation-only conflict + session continuity validator.
        $middleware->appendToGroup('web', [
            RejectTenancyContextConflict::class,
            InitializeTenancyFromSession::class,
        ]);

        /*
         * Priority map (not web-group array order) drives SortedMiddleware.
         * Insert Phase 1 before StartSession by walking reverse so Classifier ends earliest:
         * Classifier → … → SessionConnection → StartSession → Conflict → FromSession.
         * Do not prepend relative to middleware that are not yet in the default priority
         * list — that appends at the end and lets SessionConnection/StartSession sort first.
         */
        $phase1 = [
            RequestHostClassifier::class,
            InitializeTenancyByHostWhenApplicable::class,
            InitializeTenancyByPathWhenApplicable::class,
            EnsureResolvedTenantIsOperational::class,
            ConfigureApplicationRuntime::class,
            InitializeTenancyFromAuthRequest::class,
            InitializeTenancyFromSsoState::class,
            ConfigureTenantSessionConnection::class,
        ];

        $anchor = StartSession::class;
        foreach (array_reverse($phase1) as $middlewareClass) {
            $middleware->prependToPriorityList($anchor, $middlewareClass);
            $anchor = $middlewareClass;
        }

        $middleware->appendToPriorityList(StartSession::class, RejectTenancyContextConflict::class);
        $middleware->appendToPriorityList(RejectTenancyContextConflict::class, InitializeTenancyFromSession::class);

        // Inertia before auth on tenant routes (global priority).
        $middleware->appendToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
        );

        if (class_exists(\Inertia\Middleware::class)) {
            $middleware->web(append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
            ]);
        }

        $middleware->api([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
        ]);

        $middleware->alias([
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'platform.permission' => \App\Http\Middleware\EnsurePlatformPermission::class,
            'platform.no_tenancy' => \App\Http\Middleware\EnsureNoTenancy::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // BK-082: host-only SSO binding cookies are opaque proofs (not Laravel session payload).
        $middleware->encryptCookies(except: [
            \Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
            \Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::AUTH_BINDING,
        ]);

        // BK-082 IH-7: IdP form_post posts to Auth Host without a Laravel CSRF token.
        $middleware->validateCsrfTokens(except: [
            'auth/enterprise-sso/callback',
        ]);

        $middleware->redirectGuestsTo([AuthenticationRedirects::class, 'guestRedirect']);
        $middleware->redirectUsersTo([AuthenticationRedirects::class, 'authenticatedRedirect']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (DomainException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode(),
                        'message' => $e->getMessage(),
                        'request_id' => RequestContext::getInstance()->getRequestId() ?? null,
                    ],
                ], $e->getCode() ?: 500);
            }

            return redirect()->back()->with('error', $e->getMessage());
        });
    })->create();
