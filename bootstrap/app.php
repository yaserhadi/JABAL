<?php

use App\Exceptions\DomainException;
use App\Support\Context\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../Modules/Api/routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register context middleware
        // Phase 2: TenantResolverMiddleware REMOVED — use Stancl InitializeTenancyBy* instead
        $middleware->web([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
        ]);

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
            // Phase 3B: Spatie RBAC (runs after tenancy + membership)
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
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
