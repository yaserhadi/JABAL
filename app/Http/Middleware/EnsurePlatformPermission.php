<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a specific platform permission (central platform RBAC).
 */
class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! Auth::guard('platform')->check()) {
            return redirect()->route('platform.login');
        }

        /** @var PlatformUser $user */
        $user = Auth::guard('platform')->user();

        if (! $user->hasPlatformPermission($permission)) {
            abort(403, 'Platform permission required: '.$permission);
        }

        return $next($request);
    }
}
