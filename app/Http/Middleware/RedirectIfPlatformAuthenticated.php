<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
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

        $user = Auth::guard('platform')->user();
        $target = $user instanceof PlatformUser
            ? $user->homeRedirectPath()
            : route('platform.dashboard');

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->to($target);
    }
}
