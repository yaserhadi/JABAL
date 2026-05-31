<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guard-aware redirects for platform vs tenant application (ADR-0007).
 */
final class AuthenticationRedirects
{
    public static function guestRedirect(Request $request): string
    {
        if ($request->is('platform') || $request->is('platform/*')) {
            return route('platform.login');
        }

        return route('login');
    }

    public static function authenticatedRedirect(Request $request): string
    {
        if (Auth::guard('platform')->check()) {
            return route('platform.settings.index');
        }

        $user = Auth::guard('web')->user();

        if ($user !== null) {
            if (method_exists($user, 'personalTenant')) {
                $tenant = $user->personalTenant();
                if ($tenant !== null) {
                    return route('dashboard', ['tenant' => $tenant->id]);
                }
            }

            // Stale tenant session (authenticated web user without resolvable tenant) — avoid /login loop.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return route('login');
    }
}
