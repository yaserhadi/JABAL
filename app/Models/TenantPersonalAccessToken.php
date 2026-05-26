<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens for tenant-application users (tenant DB).
 */
class TenantPersonalAccessToken extends PersonalAccessToken
{
    protected $connection = 'tenant';

    protected $table = 'personal_access_tokens';
}
