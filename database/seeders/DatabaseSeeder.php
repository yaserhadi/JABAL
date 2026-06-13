<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformAdminSeeder::class,
            PlatformRbacSeeder::class,
            TenantContactRoleSeeder::class,
            RbacCatalogSeeder::class,
            SystemSettingsSeeder::class,
        ]);
    }
}
