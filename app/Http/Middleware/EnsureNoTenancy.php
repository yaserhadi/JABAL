<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform Management routes must not run with active tenant context.
 */
class EnsureNoTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            abort(404);
        }

        return $next($request);
    }
}
