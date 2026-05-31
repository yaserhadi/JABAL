<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run path-based tenancy init early in the web stack (before auth/Inertia).
 */
class InitializeTenancyByPathWhenApplicable
{
    public function handle(Request $request, Closure $next): Response
    {
        $pathTenantId = $this->resolvePathTenantId($request);

        if ($pathTenantId) {
            $currentId = tenancy()->tenant?->id;

            if (! tenancy()->initialized || $currentId !== $pathTenantId) {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }

                $tenant = Tenant::query()->find($pathTenantId);

                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            }
        }

        return $next($request);
    }

    private function resolvePathTenantId(Request $request): ?string
    {
        if ($request->segment(1) !== 't') {
            return null;
        }

        $id = $request->segment(2);

        return ($id && Str::isUuid($id)) ? $id : null;
    }
}
