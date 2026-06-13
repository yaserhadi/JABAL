<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Identity\Models\Membership;

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

        // Create membership on the tenant database/connection
        tenancy()->initialize($tenant);
        Membership::firstOrCreate(
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
        tenancy()->end();
    }
}
