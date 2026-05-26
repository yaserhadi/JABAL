<?php

namespace App\Models\Rbac;

use Spatie\Permission\Models\Permission;

/**
 * Tenant-application RBAC permissions (jabal_tenant_shared).
 */
class TenantPermission extends Permission
{
    protected $connection = 'tenant';
}
