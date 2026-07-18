<?php

namespace App\Http\Middleware;

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
 */
class InitializeTenancyFromSession
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->addressing->isHost()) {
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
            abort(403, 'Tenant session conflict.');
        }

        return $next($request);
    }
}
