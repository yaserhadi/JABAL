<?php

namespace App\Models\Rbac;

use Spatie\Permission\Models\Role;

/**
 * Tenant-application RBAC roles (jabal_tenant_shared).
 */
class TenantRole extends Role
{
    protected $connection = 'tenant';
}
