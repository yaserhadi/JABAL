<?php

namespace Tests;

use App\Support\Context\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set the currently authenticated user to act as a specific tenant.
     *
     * @param  mixed  $tenant
     * @return $this
     */
    protected function actingAsTenant($tenant)
    {
        TenantContext::getInstance()->set($tenant);

        return $this;
    }

    /**
     * Create a personal tenant for the given user.
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
        $tenant = $tenant ?? TenantContext::getInstance()->get();
        $this->assertNotNull($tenant);
        $this->assertEquals($tenant->id, $model->tenant_id ?? $model->getAttribute('tenant_id'));
    }
}
