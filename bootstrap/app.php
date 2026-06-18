<?php

use App\Exceptions\DomainException;
use App\Http\Auth\AuthenticationRedirects;
use App\Support\Context\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../Modules/Api/routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register context middleware
        // Phase 2: TenantResolverMiddleware REMOVED — use Stancl InitializeTenancyBy* instead
        $middleware->web([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
        ]);

        // Platform vs tenant session runtime before StartSession (ADR-0007 §3.1.3.1).
        $middleware->prependToGroup('web', [
            \App\Http\Middleware\ConfigureApplicationRuntime::class,
        ]);
        $middleware->prependToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\ConfigureApplicationRuntime::class,
        );

        // Tenancy only on tenant path routes (not platform / global web).
        // auth was running before appended web middleware; enforce tenancy init first on tenant routes.
        $middleware->appendToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\InitializeTenancyFromSession::class,
        );
        $middleware->appendToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\InitializeTenancyByPathWhenApplicable::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\InitializeTenancyByPathWhenApplicable::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
        );

        // Inertia middleware (PR-2): only register when inertiajs/inertia-laravel is installed
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
            'platform.no_tenancy' => \App\Http\Middleware\EnsureNoTenancy::class,
            // Phase 3B: Spatie RBAC (runs after tenancy + membership)
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Platform vs tenant login/home (avoid route('dashboard') without {tenant} on platform guard).
        $middleware->redirectGuestsTo([AuthenticationRedirects::class, 'guestRedirect']);
        $middleware->redirectUsersTo([AuthenticationRedirects::class, 'authenticatedRedirect']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Custom exception handling for DomainException
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
