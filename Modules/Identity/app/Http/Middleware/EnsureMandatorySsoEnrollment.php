<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SsoReadinessAccountingService;
use Modules\Identity\Support\Auth\SsoUserReadinessState;
use Symfony\Component\HttpFoundation\Response;

/**
 * WAVE-5: Mandatory SSO Enrollment — block normal application until Ready or valid Exception.
 * No Skip / Maybe Later. Linked alone does not unblock.
 */
class EnsureMandatorySsoEnrollment
{
    public function __construct(
        protected SecurityPolicyService $policies,
        protected SsoReadinessAccountingService $accounting,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenancy()->tenant;
        $user = $request->user();

        if (! $tenant || ! $user) {
            return $next($request);
        }

        if (! $this->policies->isMandatorySsoEnrollmentActive($tenant)) {
            return $next($request);
        }

        $classified = $this->accounting->classifyUser($tenant, $user);
        if (in_array($classified['state'], [
            SsoUserReadinessState::READY,
            SsoUserReadinessState::EXCEPTION,
            SsoUserReadinessState::INELIGIBLE,
        ], true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => 'Mandatory SSO enrollment required',
                'code' => 'mandatory_sso_enrollment_required',
            ], 403);
        }

        return redirect()->to(
            app(\App\Http\Auth\TenantEntryUrlResolver::class)
                ->namedRouteUrl('identity.sso.mandatory-enrollment', $tenant)
        );
    }
}
