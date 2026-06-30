<?php

namespace Tests\Feature;

use App\Support\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Support\Facades\Config;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantOnboardingService;
use Tests\TestCase;

class TenantProvisionerTest extends TestCase
{
    public function test_automatic_strategy_provisions_dedicated_storage_inline(): void
    {
        config([
            'tenancy_storage.mode' => 'database_per_tenant',
            'tenancy_storage.db_creation_mode' => 'automatic',
        ]);

        $input = new TenantOnboardingInput(
            organizationName: 'Auto Provision Org',
            ownerName: 'Auto Owner',
            ownerEmail: 'auto-owner-'.uniqid().'@example.com',
            ownerPassword: 'password-Str0ng!',
            isolationLevel: 'database',
        );

        $service = app(TenantOnboardingService::class);
        $result = $service->onboardOrganizationTenant($input);

        $this->assertTrue($result->r2Storage, 'R2 should be satisfied inline for automatic strategy');
        $this->assertSame('active', $result->tenant->databaseConfig?->provisioning_status);
        $this->assertTrue($result->r3Rbac);
        $this->assertTrue($result->r5OwnerAuth);
    }

    public function test_provisioner_is_idempotent_when_already_active(): void
    {
        config(['tenancy_storage.mode' => 'database_per_tenant']);

        $tenant = Tenant::query()->create([
            'name' => 'Idempotent Org',
            'slug' => 'idem-'.uniqid(),
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ]);

        $sharedTestingDatabase = (string) config('database.connections.tenant.database');

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => $sharedTestingDatabase,
            'provisioning_status' => 'active',
        ]);

        $connectionName = 'tenant_db_'.$tenant->id;
        Config::set('database.connections.'.$connectionName, array_merge(
            config('database.connections.tenant'),
            ['database' => $sharedTestingDatabase]
        ));

        $provisioner = app(TenantDatabaseProvisioner::class);
        $provisioner->provision($tenant->fresh(['databaseConfig']));

        $this->assertSame('active', $tenant->fresh(['databaseConfig'])->databaseConfig?->provisioning_status);
    }

    public function test_provisioner_refuses_replay_when_status_is_failed(): void
    {
        config(['tenancy_storage.mode' => 'database_per_tenant']);

        $tenant = Tenant::query()->create([
            'name' => 'Failed Org',
            'slug' => 'fail-'.uniqid(),
            'type' => 'organization',
            'isolation_level' => 'database',
            'status' => 'active',
        ]);

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => 'jabal_tenant_dedicated_a_testing',
            'provisioning_status' => 'failed',
        ]);

        $provisioner = app(TenantDatabaseProvisioner::class);

        $this->expectException(\RuntimeException::class);
        $provisioner->provision($tenant->fresh(['databaseConfig']));
    }
}
