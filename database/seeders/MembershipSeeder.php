<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class MembershipSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', config('app.admin_email', 'admin@example.com'))->first();
        $tenant = $user
            ? Tenant::where('slug', Str::slug($user->name).'-personal')->first()
            : null;

        if (! $user || ! $tenant) {
            return;
        }

        TenantUser::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ],
            [
                'membership_type' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]
        );
    }
}
