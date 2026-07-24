<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAddressingProfile;
use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * BK-008: Initialize tenancy for OIDC callback from encrypted state only (never query tenant_id).
 *
 * Path profile: initializes tenancy for auth/sso/callback.
 * Host profile: Path-era callback is not registered (BK-103); skip Path init and pass through
 * so the request remains an unregistered-route 404 without touching Tenant context.
 */
class InitializeTenancyFromSsoState
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('auth/sso/callback')) {
            return $next($request);
        }

        // BK-103 SSO-LG-005: Host does not own Path callback tenancy initialization.
        if ($this->addressing->isHost()) {
            return $next($request);
        }

        $state = $request->query('state');

        if (! is_string($state) || $state === '') {
            abort(403, 'Invalid SSO callback.');
        }

        try {
            $parsed = SsoAuthorizationState::parse($state);
        } catch (SsoSecurityException $exception) {
            abort(403, $exception->getMessage());
        }

        $tenant = Tenant::query()->find($parsed['tenant_id']);

        if (! $tenant || $tenant->status !== 'active') {
            abort(403, 'Invalid SSO callback tenant.');
        }

        if (tenancy()->initialized && tenancy()->tenant?->id !== $tenant->id) {
            tenancy()->end();
        }

        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        $request->attributes->set('sso_callback_tenant_id', $tenant->id);

        return $next($request);
    }
}
