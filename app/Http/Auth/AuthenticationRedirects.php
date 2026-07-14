<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\Membership;

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
            $tenant = method_exists($user, 'homeTenant')
                ? $user->homeTenant()
                : null;

            if ($tenant !== null) {
                $hasMembership = false;

                try {
                    if (tenancy()->initialized && tenancy()->tenant?->id === $tenant->id) {
                        $hasMembership = Membership::query()
                            ->where('user_id', $user->id)
                            ->where('tenant_id', $tenant->id)
                            ->where('status', 'active')
                            ->exists();
                    } else {
                        tenancy()->initialize($tenant);
                        try {
                            $hasMembership = Membership::query()
                                ->where('user_id', $user->id)
                                ->where('tenant_id', $tenant->id)
                                ->where('status', 'active')
                                ->exists();
                        } finally {
                            tenancy()->end();
                        }
                    }
                } catch (\Throwable) {
                    $hasMembership = false;
                }

                if ($hasMembership) {
                    return route('dashboard', ['tenant' => $tenant->entryKey()]);
                }
            }

            // Stale tenant session (authenticated web user without resolvable membership) — avoid /login loop.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return route('login');
    }
}
