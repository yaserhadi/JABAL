<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initialize tenancy from session tenant_id before auth/Inertia resolve tenant users.
 */
class InitializeTenancyFromSession
{
    public function handle(Request $request, Closure $next): Response
    {
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
}
