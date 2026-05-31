<?php

namespace Database\Seeders;

use App\Models\PlatformUser;
use Illuminate\Database\Seeder;

/**
 * Platform operator — not a tenant application user (ADR-0007).
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        PlatformUser::firstOrCreate(
            ['email' => config('app.admin_email')],
            [
                'name' => config('app.admin_name'),
                'password' => config('app.admin_password'),
                'is_active' => true,
            ]
        );
    }
}
