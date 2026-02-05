<?php

use App\Exceptions\DomainException;
use App\Support\Context\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register context middleware
        $middleware->web([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
            \App\Http\Middleware\TenantResolverMiddleware::class,
        ]);

        $middleware->api([
            \App\Http\Middleware\RequestContextMiddleware::class,
            \App\Http\Middleware\ExecutionContextMiddleware::class,
            \App\Http\Middleware\TenantResolverMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle DomainException with consistent API/Web responses
        $exceptions->render(function (DomainException $e, $request) {
            $requestContext = RequestContext::getInstance();

            if ($request->expectsJson()) {
                // API Response
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode(),
                        'message' => $e->getMessage(),
                        'details' => $e->errorDetails(),
                    ],
                    'meta' => [
                        'request_id' => $requestContext->requestId(),
                        'timestamp' => now()->toIso8601String(),
                    ],
                ], $e->getCode() ?: 500);
            }

            // Web Response: Redirect back with error message
            if ($request->hasSession()) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'error' => $e->getMessage(),
                    ]);
            }

            // Fallback: Simple error response
            return response()->view('errors.custom', [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        });
    })->create();
