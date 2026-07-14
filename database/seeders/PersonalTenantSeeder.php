<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;

class PersonalTenantSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', config('app.admin_email', 'admin@example.com'))->first();

        if (! $user) {
            return;
        }

        Tenant::firstOrCreate(
            [
                'slug' => Str::slug($user->name).'-home',
            ],
            [
                'name' => $user->name.'\'s Workspace',
                'isolation_level' => 'shared',
                'status' => 'active',
                'created_by' => $user->id,
            ]
        );
    }
}
