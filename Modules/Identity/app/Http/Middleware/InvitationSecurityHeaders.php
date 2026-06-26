<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reduce token leakage via Referer on public invitation accept pages.
 */
class InvitationSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->header('Referrer-Policy', 'no-referrer');
    }
}
