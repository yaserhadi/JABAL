<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\Tenant;
use Modules\Workspaces\Models\Workspace;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3A: Cross-tenant isolation tests for Workspace domain model.
 *
 * Verifies:
 * - Query without tenant context fails
 * - Create without context fails (app flow must never do this)
 * - Create with tenant context auto-sets tenant_id
 * - Tenant A data is invisible to tenant B
 * - Model-level bootstrap: explicit tenant_id allowed for seed/test/console only
 */
class WorkspaceTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure tenant migrations have run (workspaces table exists in jabal_tenant_shared)
        $this->artisan('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
        ]);
    }

    public function test_query_without_tenant_context_throws_exception(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot query tenant-scoped model');

        Workspace::query()->get();
    }

    public function test_create_without_context_throws_exception(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create tenant-scoped model');

        Workspace::create(['name' => 'Test', 'slug' => 'test']);
    }

    public function test_create_with_tenant_context_auto_sets_tenant_id(): void
    {
        $user = \Modules\Identity\Models\TenantUser::factory()->create();
        $tenant = $this->createPersonalTenant($user);

        $this->actingAsTenant($tenant);

        $workspace = Workspace::create(['name' => 'My Workspace', 'slug' => 'my-workspace']);

        $this->assertEquals($tenant->id, $workspace->tenant_id);
    }

    public function test_tenant_a_data_invisible_to_tenant_b(): void
    {
        $userA = \Modules\Identity\Models\TenantUser::factory()->create();
        $userB = \Modules\Identity\Models\TenantUser::factory()->create();
        $tenantA = $this->createPersonalTenant($userA);
        $tenantB = $this->createPersonalTenant($userB);

        $this->actingAsTenant($tenantA);
        $workspaceA = Workspace::create(['name' => 'A Workspace', 'slug' => 'a-workspace']);

        $this->actingAsTenant($tenantB);
        $workspaces = Workspace::all();

        $this->assertCount(0, $workspaces);
        $this->assertNull(Workspace::find($workspaceA->id));
    }

    /**
     * Model-level bootstrap test: explicit tenant_id succeeds.
     * Allowed ONLY for seed/test/console bootstrap, not for normal app flows.
     */
    public function test_model_level_bootstrap_explicit_tenant_id_succeeds(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $user = \Modules\Identity\Models\TenantUser::factory()->create();
        $tenant = $this->createPersonalTenant($user);

        $workspace = new Workspace;
        $workspace->tenant_id = $tenant->id;
        $workspace->name = 'Bootstrap Workspace';
        $workspace->slug = 'bootstrap-workspace';
        $workspace->save();

        $this->assertEquals($tenant->id, $workspace->tenant_id);
    }
}
