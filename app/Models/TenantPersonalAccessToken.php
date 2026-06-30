<?php

namespace App\Models;

use App\Support\Traits\ResolvesTenantStorageConnection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens for tenant-application users (tenant DB).
 */
class TenantPersonalAccessToken extends PersonalAccessToken
{
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'personal_access_tokens';
}
