<?php

namespace App\Models;

/**
 * Thin bridge for Laravel auth/Sanctum (tenant application users).
 * @see TenantUser
 */
class User extends \Modules\Identity\Models\TenantUser {}
