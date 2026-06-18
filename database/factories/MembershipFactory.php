<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    protected $connection = 'tenant';

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => \App\Models\User::factory(),
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ];
    }
}
