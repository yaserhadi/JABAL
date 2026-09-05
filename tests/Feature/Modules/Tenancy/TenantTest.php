<?php

namespace Tests\Feature\Modules\Tenancy;

use Modules\Identity\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_be_created(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
    }

    public function test_user_can_have_home_tenant(): void
    {
        $user = TenantUser::factory()->create();
        $userService = app(\Modules\Identity\Services\UserService::class);
        $homeTenant = $userService->resolveHomeTenant($user);

        $this->assertNotNull($homeTenant);
        $this->assertEquals($user->id, $homeTenant->created_by);
    }

    public function test_user_can_belong_to_multiple_tenants(): void
    {
        $user = TenantUser::factory()->create();

        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        tenancy()->initialize($tenant1);
        Membership::factory()->create([
            'tenant_id' => $tenant1->id,
            'user_id' => $user->id,
        ]);
        tenancy()->end();

        tenancy()->initialize($tenant2);
        Membership::factory()->create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user->id,
        ]);
        tenancy()->end();

        $userService = app(\Modules\Identity\Services\UserService::class);
        $userTenants = $userService->getTenants($user);

        $this->assertGreaterThanOrEqual(2, $userTenants->count());
    }

    public function test_tenant_can_have_multiple_members(): void
    {
        $tenant = Tenant::factory()->create();
        $users = TenantUser::factory()->count(3)->create();

        tenancy()->initialize($tenant);
        foreach ($users as $user) {
            Membership::factory()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ]);
        }

        $this->assertSame(3, Membership::query()->where('tenant_id', $tenant->id)->count());
        tenancy()->end();
    }
}
