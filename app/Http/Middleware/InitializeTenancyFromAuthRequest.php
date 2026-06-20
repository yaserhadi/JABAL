<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initialize tenancy from POST /login or POST /register before StartSession (database_per_tenant).
 */
class InitializeTenancyFromAuthRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized || ! $this->isEligibleAuthPost($request)) {
            return $next($request);
        }

        $tenant = $this->resolveTenantFromEmail($request);

        if ($tenant instanceof Tenant && $tenant->status === 'active') {
            tenancy()->initialize($tenant);
        }

        return $next($request);
    }

    private function isEligibleAuthPost(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        if ($request->is('platform', 'platform/*', 'api', 'api/*')) {
            return false;
        }

        if ($request->segment(1) === 't') {
            return false;
        }

        $path = $request->path();

        if (str_starts_with($path, 'password')) {
            return false;
        }

        return in_array($path, ['login', 'register'], true);
    }

    private function resolveTenantFromEmail(Request $request): ?Tenant
    {
        $email = $request->input('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        $tenantUser = TenantUser::findForLogin($email);

        if (! $tenantUser) {
            return null;
        }

        return Tenant::query()->find($tenantUser->tenant_id);
    }
}
