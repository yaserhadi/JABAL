<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\TenantRegistrationService;

/**
 * BK-116: bootstrap lab admin via TenantRegistrationService (TenantUser-only; legacy User bridge absent).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('app.admin_email');
        $name = (string) config('app.admin_name', 'Admin');
        $password = (string) config('app.admin_password', 'password');

        $existing = TenantUser::withoutGlobalScope('tenant')
            ->where('email', $email)
            ->first();

        if ($existing) {
            return;
        }

        app(TenantRegistrationService::class)->registerTenantUser($name, $email, $password);
    }
}
