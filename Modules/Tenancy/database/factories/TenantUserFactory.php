<?php

namespace Modules\Tenancy\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Tenancy\Models\TenantUser>
 */
class TenantUserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = TenantUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ];
    }

    /**
     * Indicate that the membership is owner.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'membership_type' => 'owner',
        ]);
    }

    /**
     * Indicate that the membership is admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'membership_type' => 'admin',
        ]);
    }

    /**
     * Indicate that the membership is member.
     */
    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'membership_type' => 'member',
        ]);
    }

    /**
     * Indicate that the membership is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the membership is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
