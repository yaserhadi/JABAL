<?php

namespace App\Http\Middleware;

use App\Support\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

class TenantResolverMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Phase 1 Tenant Resolution Strategy:
     * 1. Check session for 'active_tenant_id' (web) or token abilities (API)
     * 2. If not set → use user's personal tenant (default)
     * 3. Store resolved tenant in TenantContext singleton
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = TenantContext::getInstance();
        $tenant = $this->resolveTenant($request);

        if ($tenant) {
            $context->set($tenant);
        }

        return $next($request);
    }

    /**
     * Resolve the current tenant from the request.
     *
     * Phase 1 Implementation:
     * - Web: Check session['active_tenant_id'] → fallback to personal tenant
     * - API: Check Sanctum token abilities for 'tenant:{uuid}' → fallback to personal tenant
     * - Default: User's personal tenant
     */
    private function resolveTenant(Request $request): ?Tenant
    {
        $tenantId = null;

        if ($request->expectsJson() && $request->user()) {
            $token = $request->user()->currentAccessToken();
            if ($token && ! empty($token->abilities)) {
                foreach ($token->abilities as $ability) {
                    if (is_string($ability) && str_starts_with($ability, 'tenant:')) {
                        $tenantId = substr($ability, 7);
                        break;
                    }
                }
            }
        } elseif ($request->hasSession()) {
            $tenantId = $request->session()->get('active_tenant_id');
        }

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                return $tenant;
            }
        }

        if (auth()->check()) {
            return auth()->user()->personalTenant();
        }

        return null;
    }
}
