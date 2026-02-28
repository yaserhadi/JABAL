<?php

namespace App\Models;

/**
 * Thin bridge for Laravel auth/Sanctum/config compatibility.
 * All domain logic (tenancy, personalTenant) lives in Modules\Identity\Models\User.
 * Lock 2: This file MUST NOT contain tenancy relations, business methods, or Modules imports.
 */
class User extends \Modules\Identity\Models\User {}
