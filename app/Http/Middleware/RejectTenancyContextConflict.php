<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-session validation only — never re-resolves Tenant (BK-073).
 *
 * Compares session/token claims against the already-resolved tenancy()->tenant.
 */
class RejectTenancyContextConflict
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = tenancy()->tenant;

        if ($request->hasSession() && $request->session()->isStarted()) {
            $sessionTenantId = $request->session()->get('tenant_id');
            if (is_string($sessionTenantId) && $sessionTenantId !== '') {
                if ($resolved instanceof Tenant && (string) $resolved->id !== $sessionTenantId) {
                    abort(403, 'Tenant context conflict.');
                }
            }
        }

        $headerTenantId = $request->header('X-Tenant-Id');
        if (is_string($headerTenantId) && $headerTenantId !== '') {
            if ($resolved instanceof Tenant && (string) $resolved->id !== $headerTenantId) {
                abort(403, 'Tenant context conflict.');
            }
        }

        return $next($request);
    }
}
