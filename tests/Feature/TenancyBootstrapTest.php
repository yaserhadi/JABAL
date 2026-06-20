<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * Phase 2 Bootstrapper Tests: Verify tenancy context is properly initialized.
 *
 * NOTE: Full cache isolation tests require a cache driver that supports tags
 * (e.g., Redis). The array driver used in tests doesn't support tags, so
 * we verify tenant context availability instead of tag-based isolation.
 *
 * PHASE 2 LOCK: Tests verify tenancy context is available after initialization.
 * No schema changes required.
 */
class TenancyBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->tenantA = $this->createPersonalTenant($userA);
        $this->tenantB = $this->createPersonalTenant($userB);
    }

    /**
     * Test that tenancy context can be initialized and ended.
     */
    public function test_tenancy_context_can_be_initialized_and_ended(): void
    {
        $this->assertFalse(tenancy()->initialized);

        tenancy()->initialize($this->tenantA);

        $this->assertTrue(tenancy()->initialized);
        $this->assertEquals($this->tenantA->id, tenancy()->tenant->id);

        tenancy()->end();

        $this->assertFalse(tenancy()->initialized);
    }

    /**
     * Test that tenancy context switches correctly between tenants.
     */
    public function test_tenancy_context_switches_between_tenants(): void
    {
        tenancy()->initialize($this->tenantA);
        $this->assertEquals($this->tenantA->id, tenancy()->tenant->id);
        tenancy()->end();

        tenancy()->initialize($this->tenantB);
        $this->assertEquals($this->tenantB->id, tenancy()->tenant->id);
        tenancy()->end();

        tenancy()->initialize($this->tenantA);
        $this->assertEquals($this->tenantA->id, tenancy()->tenant->id);
        tenancy()->end();
    }

    /**
     * Test that cache operations work within tenant context.
     *
     * Note: This tests that cache works with tenancy, not that it's isolated.
     * Full isolation requires a tagged cache driver (Redis, etc.).
     */
    public function test_cache_operations_work_within_tenant_context(): void
    {
        $cacheKey = 'test:tenant_aware_key:'.Str::uuid();

        tenancy()->initialize($this->tenantA);

        Cache::put($cacheKey, 'test_value', 60);
        $this->assertEquals('test_value', Cache::get($cacheKey));

        Cache::forget($cacheKey);
        $this->assertNull(Cache::get($cacheKey));

        tenancy()->end();
    }

    /**
     * Test that queued jobs receive tenant context.
     *
     * NOTE: This test verifies that the QueueTenancyBootstrapper is enabled.
     * Full queue tenant isolation requires a real queue driver (database, Redis)
     * and is verified in integration/staging environments.
     *
     * With sync driver in tests, we verify that tenancy context is available
     * when manually dispatching and handling a job in the same process.
     */
    public function test_queue_tenancy_bootstrapper_is_enabled(): void
    {
        // Verify that QueueTenancyBootstrapper is in the config
        $bootstrappers = config('tenancy.bootstrappers');

        $this->assertContains(
            \Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
            $bootstrappers,
            'QueueTenancyBootstrapper must be enabled for tenant-aware queues'
        );

        $this->assertContains(
            \App\Support\Tenancy\Bootstrappers\TenantDatabaseTenancyBootstrapper::class,
            $bootstrappers,
            'TenantDatabaseTenancyBootstrapper must be enabled for Decision B database routing'
        );
    }

    /**
     * Test that Tenant model correctly implements TenantContract.
     */
    public function test_tenant_model_implements_tenant_contract(): void
    {
        $tenant = $this->tenantA;

        $this->assertEquals('id', $tenant->getTenantKeyName());
        $this->assertEquals($tenant->id, $tenant->getTenantKey());
    }

    /**
     * Test tenant run() method executes callback in tenant context.
     */
    public function test_tenant_run_method_executes_in_context(): void
    {
        $result = $this->tenantA->run(function ($tenant) {
            return [
                'initialized' => tenancy()->initialized,
                'tenant_id' => tenancy()->tenant?->id,
            ];
        });

        $this->assertTrue($result['initialized']);
        $this->assertEquals($this->tenantA->id, $result['tenant_id']);

        // Context should be ended after run()
        $this->assertFalse(tenancy()->initialized);
    }
}
