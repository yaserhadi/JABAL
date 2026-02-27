<?php

namespace Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Version Middleware
 *
 * Checks Accept header for API version (default: v1).
 * Sets request attribute 'api_version' for use in version routing.
 */
class ApiVersionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $version = 'v1'; // Default version

        $acceptHeader = $request->header('Accept', '');

        // Check for version in Accept header (e.g., application/vnd.api.v1+json)
        if (preg_match('/application\/vnd\.api\.(v\d+)/i', $acceptHeader, $matches)) {
            $version = strtolower($matches[1]);
        }

        // Set the API version as a request attribute
        $request->attributes->set('api_version', $version);

        return $next($request);
    }
}
