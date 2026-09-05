<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Models\TenantUser;

/**
 * BK-116: personal tenant is created by TenantRegistrationService in AdminUserSeeder.
 * Kept as a safe no-op / attestation for legacy call sites.
 */
class PersonalTenantSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('app.admin_email', 'admin@example.com');

        $user = TenantUser::withoutGlobalScope('tenant')
            ->where('email', $email)
            ->first();

        if (! $user) {
            return;
        }

        // Registration path already binds an owner personal/workspace tenant.
        if ($user->personalTenant() === null && $this->command) {
            $this->command->warn('Admin TenantUser exists without personal tenant; run AdminUserSeeder via TenantRegistrationService.');
        }
    }
}
