<?php

namespace App\Http\Middleware;

use App\Http\Auth\TenantEntryUrlResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser as TenantApplicationUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web middleware: Ensure authenticated user belongs to the current tenant.
 *
 * PHASE 2 LOCK:
 * - Runs AFTER InitializeTenancyByPath
 * - Checks tenant context, route param match, tenant status, and membership
 * - Handles route param as Model, UUID, or slug (BK-064)
 */
class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenancy()->tenant;

        if (! $tenant) {
            abort(403, 'No tenant context');
        }

        $routeTenantId = $this->resolveRouteTenantId($request);

        if ($routeTenantId && $routeTenantId !== $tenant->id) {
            abort(403, 'Route tenant does not match tenancy context');
        }

        if ($tenant->status !== 'active') {
            abort(403, 'Tenant is not active');
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->guest(app(TenantEntryUrlResolver::class)->guestRedirectUrl($request));
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

    private function resolveRouteTenantId(Request $request): ?string
    {
        $routeTenant = $request->route('tenant');

        if (is_object($routeTenant)) {
            return $routeTenant->id ?? null;
        }

        $key = is_string($routeTenant) && $routeTenant !== ''
            ? $routeTenant
            : ($request->segment(1) === 't' ? $request->segment(2) : null);

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (Str::isUuid($key)) {
            return $key;
        }

        return Tenant::query()->where('slug', $key)->value('id');
    }
}
