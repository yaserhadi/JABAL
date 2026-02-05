<?php

namespace App\Http\Middleware;

use App\Support\Context\ExecutionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExecutionContextMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = ExecutionContext::getInstance();
        
        // Determine execution mode
        $mode = $this->determineMode($request);
        $context->setMode($mode);

        return $next($request);
    }

    /**
     * Determine the execution mode from the request.
     */
    private function determineMode(Request $request): string
    {
        // Check if running in test environment
        if (app()->environment('testing')) {
            return 'test';
        }

        // Check if running in console
        if (app()->runningInConsole()) {
            return 'cli';
        }

        // Check if API request (JSON expected or /api route)
        if ($request->expectsJson() || $request->is('api/*')) {
            return 'api';
        }

        // Default to web
        return 'web';
    }
}
