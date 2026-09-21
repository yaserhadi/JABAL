<?php

namespace Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Services\MfaService;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaVerified
{
    public function __construct(
        protected MfaService $mfaService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenancy()->tenant;
        $user = $request->user();

        if (! $tenant || ! $user) {
            return $next($request);
        }

        if (! $this->mfaService->isMfaRequired($tenant)) {
            return $next($request);
        }

        if (! $this->mfaService->userHasConfirmedMfa($user)) {
            // Policy administration must not hijack an existing session into enrollment.
            // Enrollment is enforced only after a fresh authentication boundary (next login).
            if (! $this->mfaService->postLoginEnrollmentRequired($request)) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'MFA enrollment required',
                    'code' => 'mfa_enrollment_required',
                ], 403);
            }

            return redirect()->to(
                app(\App\Http\Auth\TenantEntryUrlResolver::class)
                    ->namedRouteUrl('identity.mfa.enroll', $tenant)
            );
        }

        if (! $this->mfaService->sessionIsMfaVerified()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'MFA challenge required',
                    'code' => 'mfa_challenge_required',
                ], 403);
            }

            return redirect()->to(
                app(\App\Http\Auth\TenantEntryUrlResolver::class)
                    ->namedRouteUrl('identity.mfa.challenge', $tenant)
            );
        }

        return $next($request);
    }
}
