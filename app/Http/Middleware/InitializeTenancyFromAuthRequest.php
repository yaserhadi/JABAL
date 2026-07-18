<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initialize tenancy from POST /login or POST /register before StartSession (database_per_tenant).
 *
 * BK-073 Host profile: never calls tenancy()->initialize().
 * Validates email-derived Tenant against already-resolved context when both present.
 */
class InitializeTenancyFromAuthRequest
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->addressing->isHost()) {
            return $this->validateOnlyInHostMode($request, $next);
        }

        if (tenancy()->initialized || ! $this->isEligibleAuthPost($request)) {
            return $next($request);
        }

        $tenant = $this->resolveTenantFromEmail($request);

        if ($tenant instanceof Tenant && $tenant->status === 'active') {
            tenancy()->initialize($tenant);
        }

        return $next($request);
    }

    private function validateOnlyInHostMode(Request $request, Closure $next): Response
    {
        if (! $this->isEligibleAuthPost($request)) {
            return $next($request);
        }

        $fromEmail = $this->resolveTenantFromEmail($request);
        $resolved = tenancy()->tenant;

        if ($fromEmail instanceof Tenant && $resolved instanceof Tenant && (string) $fromEmail->id !== (string) $resolved->id) {
            abort(403, 'Tenant auth context conflict.');
        }

        // Never initialize in Host mode.
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
