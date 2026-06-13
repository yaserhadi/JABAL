<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to platform operators (platform_users guard).
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('platform')->check()) {
            return redirect()->route('platform.login');
        }

        /** @var PlatformUser $user */
        $user = Auth::guard('platform')->user();

        if (! $user->canAccessPlatform()) {
            abort(403, 'Platform admin access required');
        }

        return $next($request);
    }
}
