<?php

use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * PHASE 2: Helpers now use Stancl tenancy() instead of TenantContext.
 * tenancy() is the global Stancl helper function.
 */
if (! function_exists('current_tenant')) {
    function current_tenant(): ?Tenant
    {
        if (! tenancy()->initialized) {
            return null;
        }

        return tenancy()->tenant;
    }
}

if (! function_exists('tenant')) {
    function tenant(): Tenant
    {
        $t = current_tenant();

        if ($t === null) {
            throw new \RuntimeException('Tenant context is not set.');
        }

        return $t;
    }
}

if (! function_exists('is_tenant_context')) {
    function is_tenant_context(): bool
    {
        return tenancy()->initialized && tenancy()->tenant !== null;
    }
}

if (! function_exists('current_actor')) {
    function current_actor(): ?\App\Models\User
    {
        return auth()->user();
    }
}

if (! function_exists('actor')) {
    function actor(): \App\Models\User
    {
        $user = auth()->user();

        if ($user === null) {
            throw new \RuntimeException('No authenticated user (actor) in context.');
        }

        return $user;
    }
}

if (! function_exists('membership')) {
    function membership(): ?TenantUser
    {
        $user = auth()->user();
        $tenant = current_tenant();

        if ($user === null || $tenant === null) {
            return null;
        }

        return TenantUser::where('user_id', $user->id)->where('tenant_id', $tenant->id)->first();
    }
}

if (! function_exists('is_owner')) {
    function is_owner(): bool
    {
        $m = membership();

        return $m !== null && $m->isOwner();
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        $m = membership();

        return $m !== null && $m->isAdmin();
    }
}

if (! function_exists('membership_type')) {
    function membership_type(): ?string
    {
        $m = membership();

        return $m?->membership_type;
    }
}
