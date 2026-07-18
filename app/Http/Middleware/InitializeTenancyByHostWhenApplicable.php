<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host-profile Tenant resolution via Stancl only (BK-073).
 *
 * Runs only when profile is host AND classifier says tenant_candidate.
 * Never performs Host→slug lookup itself.
 */
class InitializeTenancyByHostWhenApplicable
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->addressing->isHost()) {
            return $next($request);
        }

        $class = RequestHostClassifier::classOf($request);
        if ($class !== RequestHostClassifier::CLASS_TENANT_CANDIDATE) {
            return $next($request);
        }

        return app(PreventAccessFromCentralDomains::class)->handle(
            $request,
            function (Request $request) use ($next) {
                return app(InitializeTenancyByDomainOrSubdomain::class)->handle($request, $next);
            }
        );
    }
}
