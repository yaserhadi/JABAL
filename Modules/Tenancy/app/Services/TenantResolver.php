<?php

namespace Modules\Tenancy\Services;

use App\Exceptions\Tenancy\TenantNotFoundException;
use App\Support\Contracts\Tenancy\TenantResolverInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Models\Tenant;

/**
 * Tenant Resolver Service.
 *
 * Resolves the current tenant from session or user's personal tenant.
 * Implements the TenantResolverInterface for dependency injection.
 */
class TenantResolver implements TenantResolverInterface
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Resolve the current tenant, returns null if no tenant found.
     *
     * Resolution strategy:
     * 1. Check session for 'active_tenant_id'
     * 2. Fallback to authenticated user's personal tenant
     *
     * @return \Modules\Tenancy\Models\Tenant|null
     */
    public function resolve(): ?object
    {
        // Check session for active tenant ID
        $tenantId = Session::get('active_tenant_id');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                return $tenant;
            }
        }

        // Fallback to user's personal tenant using UserService
        if (Auth::check()) {
            return $this->userService->getPersonalTenant(Auth::user());
        }

        return null;
    }

    /**
     * Resolve the current tenant, throws exception if no tenant found.
     *
     * @return \Modules\Tenancy\Models\Tenant
     *
     * @throws \App\Exceptions\Tenancy\TenantNotFoundException
     */
    public function resolveOrFail(): object
    {
        $tenant = $this->resolve();

        if ($tenant === null) {
            throw new TenantNotFoundException('No tenant could be resolved for the current context.');
        }

        return $tenant;
    }
}
