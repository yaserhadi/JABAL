<?php

namespace App\Http\Middleware;

use App\Http\Tenancy\TenantSessionMismatchGuard;
use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initialize tenancy from session tenant_id before auth/Inertia resolve tenant users.
 *
 * BK-073 Host profile: never calls tenancy()->initialize().
 * - No resolved Tenant → no-op
 * - Session claim present → validate against tenancy()->tenant (fail closed on mismatch)
 *
 * BK-125 Wave 7: mismatch invalidates session (no silent remap).
 */
class InitializeTenancyFromSession
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->addressing->isPathHost()) {
            return $this->validateOnlyInHostMode($request, $next);
        }

        // Path routes must resolve tenant from URL, not a possibly stale session value.
        $isTenantPath = $request->segment(1) === 't';
        if (
            ! $isTenantPath
            && ! tenancy()->initialized
            && $request->hasSession()
            && $request->session()->isStarted()
        ) {
            $tenantId = $request->session()->get('tenant_id');

            if ($tenantId) {
                $tenant = Tenant::find($tenantId);

                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            }
        }

        // PATH: when URL already resolved a Tenant, enforce session Tenant-ID match.
        if ($isTenantPath && $request->hasSession() && $request->session()->isStarted()) {
            $sessionTenantId = $request->session()->get('tenant_id');
            $resolved = tenancy()->tenant;
            if (
                is_string($sessionTenantId)
                && $sessionTenantId !== ''
                && $resolved instanceof Tenant
                && (string) $resolved->id !== $sessionTenantId
            ) {
                TenantSessionMismatchGuard::denyAndInvalidate($request);
            }
        }

        return $next($request);
    }

    private function validateOnlyInHostMode(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return $next($request);
        }

        $sessionTenantId = $request->session()->get('tenant_id');
        if (! is_string($sessionTenantId) || $sessionTenantId === '') {
            return $next($request);
        }

        $resolved = tenancy()->tenant;
        if (! $resolved instanceof Tenant) {
            // Host mode: do not initialize from session — Phase 1 is the sole resolver.
            return $next($request);
        }

        if ((string) $resolved->id !== $sessionTenantId) {
            TenantSessionMismatchGuard::denyAndInvalidate($request);
        }

        return $next($request);
    }
}
