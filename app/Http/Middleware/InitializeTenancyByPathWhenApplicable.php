<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run path-based tenancy init early in the web stack (before auth/Inertia).
 * BK-064: segment 2 may be UUID (machine) or slug (human entry).
 * BK-073: active only when addressing profile is path; no-op in Host profile.
 */
class InitializeTenancyByPathWhenApplicable
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->addressing->isPath()) {
            return $next($request);
        }

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

        $key = $request->segment(2);

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (Str::isUuid($key)) {
            return $key;
        }

        return Tenant::query()->where('slug', $key)->value('id');
    }
}
