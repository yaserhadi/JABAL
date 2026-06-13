<?php

namespace App\Http\Middleware;

use App\Models\TenantPersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Services\MembershipService;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * API middleware: Validate X-Tenant-Id header matches token ability and membership.
 *
 * PHASE 2 LOCK:
 * - Highest middleware priority (AppServiceProvider) so bearer/header checks run before
 *   InitializeTenancyByRequestData and auth:sanctum
 * - X-Tenant-Id header is REQUIRED
 * - Token MUST have tenant:{uuid} ability (not optional)
 * - Validates: header presence, tenant status, token existence, ability match, context match, membership
 */
class ValidateTenantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerTenantId = $request->header('X-Tenant-Id');

        if (! $headerTenantId) {
            return response()->json(['success' => false, 'error' => 'X-Tenant-Id header required'], 401);
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $mismatch = $this->rejectTokenHeaderMismatchForBearer($request, $headerTenantId);
        if ($mismatch !== null) {
            return $mismatch;
        }

        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        $denied = $this->validateAuthenticatedAccess($request, $headerTenantId, $user);
        if ($denied !== null) {
            return $denied;
        }

        return $response;
    }

    private function validateAuthenticatedAccess(Request $request, string $headerTenantId, $user): ?Response
    {
        $tenant = Tenant::find($headerTenantId);
        if (! $tenant || $tenant->status !== 'active') {
            return response()->json(['success' => false, 'error' => 'Tenant not found or inactive'], 403);
        }

        $token = $user->currentAccessToken();

        if (! $token) {
            return response()->json(['success' => false, 'error' => 'No access token found'], 401);
        }

        $tokenTenantId = $this->extractTenantFromAbilities($token->abilities ?? []);
        if (! $tokenTenantId) {
            return response()->json(['success' => false, 'error' => 'Token missing tenant ability'], 403);
        }

        if ((string) $headerTenantId !== (string) $tokenTenantId) {
            return response()->json(['success' => false, 'error' => 'Header does not match token ability'], 403);
        }

        if (tenancy()->initialized && tenancy()->tenant) {
            if (tenancy()->tenant->id !== $headerTenantId) {
                return response()->json(['success' => false, 'error' => 'Tenancy context mismatch'], 403);
            }
        }

        if (! $this->userHasTenantAccess($user, $headerTenantId)) {
            return response()->json(['success' => false, 'error' => 'User is not a member of this tenant'], 403);
        }

        return null;
    }

    /**
     * Resolve tenant ability from the bearer token without loading the tokenable user
     * (avoids 401 when Stancl initialized tenancy from X-Tenant-Id before Sanctum).
     */
    private function rejectTokenHeaderMismatchForBearer(Request $request, string $headerTenantId): ?Response
    {
        $plainToken = $request->bearerToken();
        if (! $plainToken) {
            return null;
        }

        $accessToken = TenantPersonalAccessToken::findToken($plainToken);
        if (! $accessToken) {
            return null;
        }

        $tokenTenantId = $this->extractTenantFromAbilities($accessToken->abilities ?? []);
        if (! $tokenTenantId) {
            return null;
        }

        if ((string) $headerTenantId !== (string) $tokenTenantId) {
            return response()->json(['success' => false, 'error' => 'Header does not match token ability'], 403);
        }

        return null;
    }

    private function extractTenantFromAbilities(array $abilities): ?string
    {
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'tenant:')) {
                return substr($ability, 7);
            }
        }

        return null;
    }

    private function userHasTenantAccess($user, string $tenantId): bool
    {
        return app(MembershipService::class)->hasActiveMembership($user->id, $tenantId);
    }
}
