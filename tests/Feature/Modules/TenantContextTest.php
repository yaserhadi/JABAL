<?php

namespace Tests\Feature\Modules;

use Modules\Identity\Models\TenantUser;
use App\Support\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_context_can_be_set_and_retrieved(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'isolation_level' => 'shared',
        ]);

        TenantContext::getInstance()->set($tenant);

        $this->assertTrue(TenantContext::getInstance()->has());
        $this->assertEquals($tenant->id, TenantContext::getInstance()->get()->id);
    }

    public function test_personal_tenant_fallback_for_user(): void
    {
        $user = TenantUser::factory()->create();
        $tenant = $this->createPersonalTenant($user);

        $this->actingAs($user);
        $this->actingAsTenant($tenant);

        $this->assertNotNull($user->personalTenant());
        $this->assertEquals($tenant->id, $user->personalTenant()->id);
    }
}
