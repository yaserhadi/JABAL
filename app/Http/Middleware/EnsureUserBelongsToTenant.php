<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser as TenantApplicationUser;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web middleware: Ensure authenticated user belongs to the current tenant.
 *
 * PHASE 2 LOCK:
 * - Runs AFTER InitializeTenancyByPath
 * - Checks tenant context, route param match, tenant status, and membership
 * - Handles route param as both string (UUID) and Model object
 */
class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenancy()->tenant;

        if (! $tenant) {
            abort(403, 'No tenant context');
        }

        $routeTenant = $request->route('tenant');
        $routeTenantId = is_object($routeTenant) ? ($routeTenant->id ?? null) : $routeTenant;

        if (! $routeTenantId && $request->segment(1) === 't') {
            $routeTenantId = $request->segment(2);
        }

        if ($routeTenantId && $routeTenantId !== $tenant->id) {
            abort(403, 'Route tenant does not match tenancy context');
        }

        if ($tenant->status !== 'active') {
            abort(403, 'Tenant is not active');
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $isMember = Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this tenant');
        }

        $belongsInTenantStore = TenantApplicationUser::query()
            ->where('id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->exists();

        if (! $belongsInTenantStore) {
            abort(403, 'Tenant application user not found in this tenant');
        }

        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);
        // ensureRolesForTenant clears team id in finally — restore for permission middleware.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());

        return $next($request);
    }
}
