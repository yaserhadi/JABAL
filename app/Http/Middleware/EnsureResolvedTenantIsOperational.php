<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Early operational gate after Tenant resolution, before session/auth (BK-073).
 *
 * Applicability:
 * - tenant_candidate (Host) / Tenant path surface (Path): resolved Tenant required + operational
 * - reserved central surfaces: no-op (absence of Tenant is correct)
 * - unknown: never reached (classifier already rejected)
 */
class EnsureResolvedTenantIsOperational
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->expectsTenant($request)) {
            return $next($request);
        }

        $tenant = tenancy()->tenant;

        if (! $tenant instanceof Tenant) {
            abort(404, 'Tenant not found.');
        }

        if ($tenant->trashed()) {
            abort(404, 'Tenant not found.');
        }

        if ($tenant->status !== 'active') {
            abort(404, 'Tenant is not operational.');
        }

        return $next($request);
    }

    private function expectsTenant(Request $request): bool
    {
        $class = RequestHostClassifier::classOf($request);

        if (RequestHostClassifier::isReserved($class) || $class === RequestHostClassifier::CLASS_UNKNOWN) {
            return false;
        }

        if ($this->addressing->isHost()) {
            return $class === RequestHostClassifier::CLASS_TENANT_CANDIDATE;
        }

        // Path profile: Tenant expected only on /t/{key}/… surfaces.
        return $request->segment(1) === 't' && is_string($request->segment(2)) && $request->segment(2) !== '';
    }
}
