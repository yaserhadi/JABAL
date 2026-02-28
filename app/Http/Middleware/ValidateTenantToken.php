<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * API middleware: Validate X-Tenant-Id header matches token ability and membership.
 * 
 * PHASE 2 LOCK:
 * - Runs AFTER auth:sanctum and InitializeTenancyByRequestData
 * - X-Tenant-Id header is REQUIRED
 * - Token MUST have tenant:{uuid} ability (not optional)
 * - Validates: header presence, tenant status, token existence, ability match, context match, membership
 */
class ValidateTenantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerTenantId = $request->header('X-Tenant-Id');
        
        if (!$headerTenantId) {
            return response()->json(['success' => false, 'error' => 'X-Tenant-Id header required'], 401);
        }
        
        $tenant = Tenant::find($headerTenantId);
        if (!$tenant || $tenant->status !== 'active') {
            return response()->json(['success' => false, 'error' => 'Tenant not found or inactive'], 403);
        }
        
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }
        
        $token = $user->currentAccessToken();
        
        if (!$token) {
            return response()->json(['success' => false, 'error' => 'No access token found'], 401);
        }
        
        $tokenTenantId = $this->extractTenantFromAbilities($token->abilities ?? []);
        if (!$tokenTenantId) {
            return response()->json(['success' => false, 'error' => 'Token missing tenant ability'], 403);
        }
        
        if ($headerTenantId !== $tokenTenantId) {
            return response()->json(['success' => false, 'error' => 'Header does not match token ability'], 403);
        }
        
        if (tenancy()->initialized && tenancy()->tenant) {
            if (tenancy()->tenant->id !== $headerTenantId) {
                return response()->json(['success' => false, 'error' => 'Tenancy context mismatch'], 403);
            }
        }
        
        if (!$this->userHasTenantAccess($user, $headerTenantId)) {
            return response()->json(['success' => false, 'error' => 'User is not a member of this tenant'], 403);
        }
        
        return $next($request);
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
        return TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
    }
}
