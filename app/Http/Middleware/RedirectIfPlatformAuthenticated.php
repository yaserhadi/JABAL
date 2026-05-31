<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform guest routes: redirect authenticated platform operators without Inertia redirect loops.
 */
class RedirectIfPlatformAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('platform')->check()) {
            return $next($request);
        }

        $target = route('platform.settings.index');

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->to($target);
    }
}
