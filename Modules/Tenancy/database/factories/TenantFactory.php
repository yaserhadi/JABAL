<?php

namespace Modules\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Tenancy\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'isolation_level' => 'shared',
            'status' => 'active',
        ];
    }

    /**
     * @deprecated BK-064 — type removed; no-op alias.
     */
    public function personal(): static
    {
        return $this;
    }

    /**
     * @deprecated BK-064 — type removed; no-op alias.
     */
    public function organization(): static
    {
        return $this;
    }

    /**
     * Set the isolation level.
     */
    public function isolationLevel(string $level): static
    {
        return $this->state(fn (array $attributes) => [
            'isolation_level' => $level,
        ]);
    }
}
