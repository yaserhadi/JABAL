<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to platform admin (APP_ADMIN_EMAIL).
 * Use for central admin routes (e.g. settings, audit index).
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $adminEmail = config('app.admin_email');
        if (! $adminEmail || $user->email !== $adminEmail) {
            abort(403, 'Platform admin access required');
        }

        return $next($request);
    }
}
