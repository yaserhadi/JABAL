<?php

namespace App\Support\Traits;

use App\Support\Contracts\Tenancy\TenantStorageResolver;

/**
 * Resolve Eloquent connection from TenantStorageResolver when tenancy is active (DEC-0007 / BK-006).
 */
trait ResolvesTenantStorageConnection
{
    public function getConnectionName(): ?string
    {
        if (tenancy()->initialized && tenancy()->tenant) {
            return app(TenantStorageResolver::class)->connectionFor(tenancy()->tenant);
        }

        return $this->connection;
    }
}
