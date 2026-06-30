<?php

namespace App\Models\Rbac;

use App\Support\Traits\ResolvesTenantStorageConnection;
use Spatie\Permission\Models\Permission;

/**
 * Tenant-application RBAC permissions (jabal_tenant_shared).
 */
class TenantPermission extends Permission
{
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';
}
