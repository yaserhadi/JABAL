<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * BK-008: Initialize tenancy for OIDC callback from encrypted state only (never query tenant_id).
 */
class InitializeTenancyFromSsoState
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('auth/sso/callback')) {
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
