<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
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
            'type' => 'organization',
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'type' => 'organization',
        ]);
    }

    public function test_user_can_have_personal_tenant(): void
    {
        $user = User::factory()->create();
        $userService = app(\Modules\Identity\Services\UserService::class);
        $personalTenant = $userService->getPersonalTenant($user);

        $this->assertNotNull($personalTenant);
        $this->assertTrue($personalTenant->isPersonal());
        $this->assertEquals($user->id, $personalTenant->created_by);
    }

    public function test_user_can_belong_to_multiple_tenants(): void
    {
        $user = User::factory()->create();

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
        $users = User::factory()->count(3)->create();

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
