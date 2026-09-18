<?php

declare(strict_types=1);

namespace App\Http\Tenancy;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * BK-125 Wave 7 — session Tenant ID must equal address-resolved Tenant ID.
 *
 * On mismatch: invalidate the web session and deny (require re-authentication).
 * Never silently remap the session to the resolved Tenant.
 */
final class TenantSessionMismatchGuard
{
    /**
     * @throws HttpException
     */
    public static function denyAndInvalidate(Request $request, string $message = 'Tenant session conflict.'): never
    {
        if ($request->hasSession() && $request->session()->isStarted()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, $message);
    }
}
