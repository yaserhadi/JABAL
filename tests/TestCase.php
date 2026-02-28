<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * PHASE 2: TestCase uses Stancl tenancy() for tenant context.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Set the currently authenticated user to act as a specific tenant.
     * Uses Stancl tenancy() for context management.
     *
     * @param  Tenant  $tenant
     * @return $this
     */
    protected function actingAsTenant(Tenant $tenant): static
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenant);

        return $this;
    }

    /**
     * Create a personal tenant for the given user.
     * 
     * PHASE 2: Sets status = 'active'.
     *
     * @param  mixed  $user
     * @return \Modules\Tenancy\Models\Tenant
     */
    protected function createPersonalTenant($user)
    {
        $tenant = Tenant::create([
            'name' => $user->name.'\'s Workspace',
            'slug' => Str::slug($user->name).'-'.Str::random(6),
            'type' => 'personal',
            'isolation_level' => 'shared',
            'status' => 'active',
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $tenant;
    }

    /**
     * Assert that a model is properly scoped to a tenant.
     *
     * @param  mixed  $model
     * @param  mixed  $tenant
     * @return void
     */
    protected function assertTenantScoped($model, $tenant = null)
    {
        $tenant = $tenant ?? tenancy()->tenant;
        $this->assertNotNull($tenant);
        $this->assertEquals($tenant->id, $model->tenant_id ?? $model->getAttribute('tenant_id'));
    }

    /**
     * Cleanup tenancy context after each test.
     */
    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }
}
