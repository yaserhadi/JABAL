<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenancy\Models\Tenant;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Modules\Tenancy\Models\Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => str()->slug($name).'-'.str()->lower(str()->random(6)),
            'isolation_level' => 'shared',
            'status' => 'active',
        ];
    }

    /**
     * @deprecated BK-064 — type removed; kept as no-op alias for older tests.
     */
    public function personal(): static
    {
        return $this;
    }

    /**
     * @deprecated BK-064 — type removed; kept as no-op alias for older tests.
     */
    public function organization(): static
    {
        return $this;
    }
}
