<?php

namespace App\Http\Middleware;

use App\Support\Context\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequestContextMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = RequestContext::getInstance();
        $context->setFromRequest($request);

        return $next($request);
    }
}
