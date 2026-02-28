<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
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

        $personalTenant = Tenant::factory()->personal()->create([
            'name' => $user->name.' Personal',
            'slug' => 'personal-'.$user->id,
        ]);

        TenantUser::factory()->owner()->create([
            'tenant_id' => $personalTenant->id,
            'user_id' => $user->id,
        ]);

        $userService = app(\Modules\Identity\Services\UserService::class);

        $this->assertTrue($personalTenant->isPersonal());
        $this->assertNotNull($userService->getPersonalTenant($user));
        $this->assertEquals($personalTenant->id, $userService->getPersonalTenant($user)->id);
    }

    public function test_user_can_belong_to_multiple_tenants(): void
    {
        $user = User::factory()->create();

        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        TenantUser::factory()->create([
            'tenant_id' => $tenant1->id,
            'user_id' => $user->id,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user->id,
        ]);

        $userService = app(\Modules\Identity\Services\UserService::class);
        $userTenants = $userService->getTenants($user);

        $this->assertCount(2, $userTenants);
    }

    public function test_tenant_can_have_multiple_members(): void
    {
        $tenant = Tenant::factory()->create();
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            TenantUser::factory()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ]);
        }

        $this->assertCount(3, $tenant->users);
    }
}
