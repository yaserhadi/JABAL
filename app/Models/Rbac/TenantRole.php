<?php

namespace App\Models\Rbac;

use App\Support\Traits\ResolvesTenantStorageConnection;
use Spatie\Permission\Models\Role;

/**
 * Tenant-application RBAC roles (jabal_tenant_shared).
 */
class TenantRole extends Role
{
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';
}
