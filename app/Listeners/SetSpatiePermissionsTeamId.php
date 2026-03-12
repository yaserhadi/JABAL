<?php

namespace App\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * Phase 3B: Set Spatie permissions team context from current tenant.
 *
 * Request lifecycle: Runs during tenancy initialization (TenancyInitialized event),
 * before any authorization check. Clears team context when tenancy ends.
 *
 * PHASE3B-RBAC Lock: Permissions team context must be set during tenancy initialization
 * middleware or equivalent request lifecycle hook.
 */
class SetSpatiePermissionsTeamId
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            TenancyInitialized::class => 'handleTenancyInitialized',
            TenancyEnded::class => 'handleTenancyEnded',
        ];
    }

    public function handleTenancyInitialized(TenancyInitialized $event): void
    {
        $tenant = $event->tenancy->tenant;
        if ($tenant) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        }
    }

    public function handleTenancyEnded(TenancyEnded $event): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
