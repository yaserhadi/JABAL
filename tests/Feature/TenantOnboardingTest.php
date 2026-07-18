<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Data\TenantProvisioningResult;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantOnboardingService;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    protected function onboardingInput(array $overrides = []): TenantOnboardingInput
    {
        $email = 'org-owner-'.uniqid().'@example.com';

        return new TenantOnboardingInput(
            organizationName: $overrides['organization_name'] ?? 'Acme Org',
            ownerName: $overrides['owner_name'] ?? 'Org Owner',
            ownerEmail: $overrides['owner_email'] ?? $email,
            ownerPassword: $overrides['owner_password'] ?? 'password-Str0ng!',
            isolationLevel: $overrides['isolation_level'] ?? 'shared',
            slug: $overrides['slug'] ?? null,
        );
    }

    protected function assertReadyFlags(
        TenantProvisioningResult $result,
        bool $r1,
        bool $r2,
        bool $r3,
        bool $r4,
        bool $r5,
        ?bool $r6 = null,
    ): void {
        $this->assertSame($r1, $result->r1Registry, 'R1 registry');
        $this->assertSame($r2, $result->r2Storage, 'R2 storage');
        $this->assertSame($r3, $result->r3Rbac, 'R3 RBAC');
        $this->assertSame($r4, $result->r4Owner, 'R4 owner');
        $this->assertSame($r5, $result->r5OwnerAuth, 'R5 owner auth');
        if ($r6 !== null) {
            $this->assertSame($r6, $result->r6Reachable, 'R6 reachable');
        }
    }

    protected function assertR6OwnerLogin(TenantProvisioningResult $result, string $password): TenantProvisioningResult
    {
        $tenantKey = $result->tenant->entryKey();

        $response = $this->post('/t/'.$tenantKey.'/login', [
            'email' => $result->owner?->email,
            'password' => $password,
        ]);

        $response->assertRedirect($this->tenantDashboardRedirectUri($result->tenant));
        $this->assertAuthenticated('web');

        return $result->withReachable(true);
    }

    public function test_shared_db_org_onboarding_satisfies_r1_through_r6(): void
    {
        $input = $this->onboardingInput();
        $service = app(TenantOnboardingService::class);

        $result = $service->onboardOrganizationTenant($input);
        $result = $this->assertR6OwnerLogin($result, $input->ownerPassword);

        $this->assertReadyFlags($result, true, true, true, true, true, true);
        $this->assertTrue($result->isReady());

        $tenant = $result->tenant;
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'active',
            'isolation_level' => 'shared',
        ], 'central');

        $this->assertInstanceOf(TenantUser::class, $result->owner);
        $this->assertNotNull($result->owner);
    }

    public function test_platform_http_onboarding_delegates_to_service(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Provisioner',
            'email' => 'provisioner-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($platformUser);

        $ownerEmail = 'http-owner-'.uniqid().'@example.com';

        $response = $this->actingAs($platformUser, 'platform')
            ->postJson('/platform/tenants/onboard', [
                'organization_name' => 'HTTP Org',
                'owner_name' => 'HTTP Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password-Str0ng!',
                'isolation_level' => 'shared',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('ready.r1_registry', true);
        $response->assertJsonPath('ready.r2_storage', true);
        $response->assertJsonPath('ready.r3_rbac', true);
        $response->assertJsonPath('ready.r4_owner', true);
        $response->assertJsonPath('ready.r5_owner_auth', true);
        $response->assertJsonPath('ready.is_ready', false);
        $response->assertJsonPath('ready.r6_reachable', false);

        $tenantId = $response->json('tenant_id');
        $this->assertNotEmpty($tenantId);

        auth('platform')->logout();
        $this->flushSession();

        $tenant = Tenant::query()->findOrFail($tenantId);
        $login = $this->post('/t/'.$tenant->entryKey().'/login', [
            'email' => $ownerEmail,
            'password' => 'password-Str0ng!',
        ]);
        $login->assertRedirect($this->tenantDashboardRedirectUri($tenant));
    }

    public function test_artisan_onboard_organization_command_is_ready_on_shared_db(): void
    {
        $ownerEmail = 'artisan-owner-'.uniqid().'@example.com';

        $this->artisan('tenant:onboard-organization', [
            '--organization-name' => 'Artisan Org',
            '--owner-name' => 'Artisan Owner',
            '--owner-email' => $ownerEmail,
            '--owner-password' => 'password-Str0ng!',
            '--isolation-level' => 'shared',
        ])->assertSuccessful();

        $tenant = Tenant::query()->where('name', 'Artisan Org')->first();
        $this->assertNotNull($tenant);

        $this->post('/t/'.$tenant->entryKey().'/login', [
            'email' => $ownerEmail,
            'password' => 'password-Str0ng!',
        ])->assertRedirect($this->tenantDashboardRedirectUri($tenant));
    }

    public function test_manual_strategy_two_phase_provisioning_becomes_ready(): void
    {
        config([
            'tenancy_storage.mode' => 'database_per_tenant',
            'tenancy_storage.db_creation_mode' => 'manual',
        ]);

        $input = $this->onboardingInput(['isolation_level' => 'database']);
        $service = app(TenantOnboardingService::class);
        $phase1 = $service->onboardOrganizationTenant($input);

        $this->assertReadyFlags($phase1, true, false, true, true, true);
        $this->assertFalse($phase1->isReady());

        $tenant = $phase1->tenant;
        $this->assertDatabaseHas('tenant_database_config', [
            'tenant_id' => $tenant->id,
            'provisioning_status' => 'pending',
        ], 'central');

        $this->artisan('tenant:provision-storage', ['tenant' => $tenant->id])
            ->assertSuccessful();

        $tenant->refresh(['databaseConfig']);
        $this->assertSame('active', $tenant->databaseConfig?->provisioning_status);

        $phase2 = $service->completeStorageProvisioning($tenant->fresh(['databaseConfig']));
        $this->assertTrue($phase2->r2Storage);

        $phase2 = $this->assertR6OwnerLogin($phase2, $input->ownerPassword);

        $this->assertTrue($phase2->isReady());
    }

    public function test_owner_user_contract_uses_tenant_user_not_platform_user(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Operator',
            'email' => 'operator-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($platformUser);

        $ownerEmail = 'contract-owner-'.uniqid().'@example.com';
        $result = app(TenantOnboardingService::class)->onboardOrganizationTenant(
            $this->onboardingInput(['owner_email' => $ownerEmail])
        );

        $this->assertInstanceOf(TenantUser::class, $result->owner);
        $this->assertDatabaseMissing('platform_users', ['email' => $ownerEmail], 'central');

        tenancy()->initialize($result->tenant);
        try {
            $membership = Membership::query()
                ->where('user_id', $result->owner->id)
                ->where('membership_type', 'owner')
                ->where('status', 'active')
                ->first();
            $this->assertNotNull($membership);

            app(PermissionRegistrar::class)->setPermissionsTeamId($result->tenant->getTenantKey());
            $this->assertTrue($result->owner->fresh()->hasRole('tenant-admin'));
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            tenancy()->end();
        }
    }

    public function test_rejects_duplicate_owner_email_at_service_level(): void
    {
        $existingEmail = 'dup-owner-'.uniqid().'@example.com';
        $this->registerTenantUser('Existing User', $existingEmail);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        app(TenantOnboardingService::class)->onboardOrganizationTenant(
            $this->onboardingInput(['owner_email' => $existingEmail])
        );
    }

    public function test_artisan_rejects_duplicate_owner_email(): void
    {
        $existingEmail = 'artisan-dup-'.uniqid().'@example.com';
        $this->registerTenantUser('Existing User', $existingEmail);

        $this->artisan('tenant:onboard-organization', [
            '--organization-name' => 'Dup Org',
            '--owner-name' => 'Dup Owner',
            '--owner-email' => $existingEmail,
            '--owner-password' => 'password-Str0ng!',
        ])->assertFailed();
    }

    public function test_rejects_schema_isolation_level_at_service_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema');

        app(TenantOnboardingService::class)->onboardOrganizationTenant(
            $this->onboardingInput(['isolation_level' => 'schema'])
        );
    }

    public function test_platform_http_rejects_schema_isolation_level(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Provisioner',
            'email' => 'schema-guard-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($platformUser);

        $this->actingAs($platformUser, 'platform')
            ->postJson('/platform/tenants', [
                'organization_name' => 'Schema Org',
                'owner_name' => 'Schema Owner',
                'owner_email' => 'schema-owner-'.uniqid().'@example.com',
                'owner_password' => 'password-Str0ng!',
                'isolation_level' => 'schema',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['isolation_level']);
    }

    public function test_platform_http_rejects_duplicate_owner_email(): void
    {
        $existingEmail = 'http-dup-'.uniqid().'@example.com';
        $this->registerTenantUser('Existing User', $existingEmail);

        $platformUser = PlatformUser::create([
            'name' => 'Provisioner',
            'email' => 'dup-guard-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($platformUser);

        $this->actingAs($platformUser, 'platform')
            ->postJson('/platform/tenants', [
                'organization_name' => 'Dup HTTP Org',
                'owner_name' => 'Dup Owner',
                'owner_email' => $existingEmail,
                'owner_password' => 'password-Str0ng!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_email']);
    }
}
