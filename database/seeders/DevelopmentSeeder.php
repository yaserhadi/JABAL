<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\TenantRegistrationService;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Models\Tenant;

/**
 * Development lab fixtures — TenantUser-only bootstrap (BK-116).
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $registration = app(TenantRegistrationService::class);
        $userService = app(UserService::class);

        $admin = TenantUser::withoutGlobalScope('tenant')
            ->where('email', 'admin@jabal.test')
            ->first();

        if (! $admin) {
            $admin = $registration->registerTenantUser('Admin User', 'admin@jabal.test', 'password');
        }

        $orgTenant = Tenant::firstOrCreate(
            ['slug' => 'demo-org'],
            [
                'name' => 'Demo Organization',
                'isolation_level' => 'shared',
                'status' => 'active',
            ]
        );

        $userService->addUserToTenant($admin, $orgTenant, 'owner', 'active');

        $user = TenantUser::withoutGlobalScope('tenant')
            ->where('email', 'user@jabal.test')
            ->first();

        if (! $user) {
            $user = $registration->registerTenantUser('Test User', 'user@jabal.test', 'password');
        }

        $userService->addUserToTenant($user, $orgTenant, 'member', 'active');

        $this->command?->info('Development data seeded successfully!');
        $this->command?->info('Admin: admin@jabal.test / password');
        $this->command?->info('User: user@jabal.test / password');
    }
}
