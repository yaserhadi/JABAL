<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Models\Tenant;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds for development environment.
     */
    public function run(): void
    {
        $userService = app(UserService::class);

        // Create admin user
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@jabal.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // Create personal tenant for admin using UserService
        $personalTenant = $userService->createPersonalTenant($admin);

        // Create organization tenant
        $orgTenant = Tenant::create([
            'name' => 'Demo Organization',
            'slug' => 'demo-org',
            'type' => 'organization',
            'isolation_level' => 'shared',
        ]);

        // Add admin as owner of organization using UserService
        $userService->addUserToTenant($admin, $orgTenant, 'owner', 'active');

        // Create regular test user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'user@jabal.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // Create personal tenant for test user using UserService
        $userService->createPersonalTenant($user);

        // Add test user as member of demo organization using UserService
        $userService->addUserToTenant($user, $orgTenant, 'member', 'active');

        $this->command->info('Development data seeded successfully!');
        $this->command->info('Admin: admin@jabal.test / password');
        $this->command->info('User: user@jabal.test / password');
    }
}
