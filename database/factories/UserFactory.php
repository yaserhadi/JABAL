<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser as TenantMembership;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Identity\Models\TenantUser>
 */
class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    protected $connection = 'tenant';
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'factory-'.Str::uuid()->toString(),
        ]);

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($user) {
            TenantMembership::firstOrCreate(
                [
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                ],
                [
                    'membership_type' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]
            );

            Tenant::where('id', $user->tenant_id)->update([
                'created_by' => $user->id,
                'type' => 'personal',
            ]);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
