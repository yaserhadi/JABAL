<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;

/**
 * BK-116: owner membership is created by TenantRegistrationService in AdminUserSeeder.
 * Attests active owner membership for the lab admin TenantUser.
 */
class MembershipSeeder extends Seeder
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

        $hasOwner = Membership::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->exists();

        if (! $hasOwner && $this->command) {
            $this->command->warn('Admin TenantUser has no active owner membership; re-run AdminUserSeeder.');
        }
    }
}
