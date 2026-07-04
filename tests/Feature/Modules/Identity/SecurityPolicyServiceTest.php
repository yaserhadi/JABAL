<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\TenantSecurityPolicy;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

/** BK-043: SecurityPolicyService — tenant-layer security policies. */
class SecurityPolicyServiceTest extends TestCase
{
    protected SecurityPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecurityPolicyService::class);
    }

    public function test_get_for_tenant_creates_row_with_config_defaults(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->assertNull(TenantSecurityPolicy::query()->where('tenant_id', $tenant->id)->first());

        $result = $this->service->getForTenant($tenant);

        $this->assertFalse($result['mfa_required']);
        $this->assertSame(0, $result['mfa_grace_period_days']);
        $this->assertSame(-1, $result['session_idle_timeout']);
        $this->assertIsArray($result['password_policy']);
        $this->assertSame(8, $result['password_policy']['min_length']);

        $row = TenantSecurityPolicy::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($row);

        tenancy()->end();
    }

    public function test_get_for_tenant_returns_stored_values(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        TenantSecurityPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'mfa_required' => true,
            'mfa_grace_period_days' => 14,
            'password_policy' => ['min_length' => 12, 'require_uppercase' => true, 'require_number' => true, 'require_special' => false],
            'session_idle_timeout' => 30,
        ]);

        $result = $this->service->getForTenant($tenant);

        $this->assertTrue($result['mfa_required']);
        $this->assertSame(14, $result['mfa_grace_period_days']);
        $this->assertSame(30, $result['session_idle_timeout']);
        $this->assertSame(12, $result['password_policy']['min_length']);
        $this->assertTrue($result['password_policy']['require_uppercase']);

        tenancy()->end();
    }

    public function test_update_creates_policy_with_audit(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantMfaAvailable($tenant);
        tenancy()->initialize($tenant);

        $record = $this->service->update($tenant, [
            'mfa_required' => true,
            'session_idle_timeout' => 60,
        ]);

        $this->assertTrue($record->mfa_required);
        $this->assertSame(60, $record->session_idle_timeout);

        tenancy()->end();
    }

    public function test_update_rejects_mfa_required_without_entitlement(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->update($tenant, ['mfa_required' => true]);

        tenancy()->end();
    }

    public function test_is_mfa_required_returns_correct_value(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->assertFalse($this->service->isMfaRequired($tenant));

        $this->grantMfaAvailable($tenant);

        $this->service->update($tenant, ['mfa_required' => true]);
        $this->assertTrue($this->service->isMfaRequired($tenant));

        tenancy()->end();
    }

    public function test_get_password_policy_returns_complete_object(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $policy = $this->service->getPasswordPolicy($tenant);
        $this->assertArrayHasKey('min_length', $policy);
        $this->assertArrayHasKey('require_uppercase', $policy);
        $this->assertArrayHasKey('require_number', $policy);
        $this->assertArrayHasKey('require_special', $policy);

        tenancy()->end();
    }

    public function test_get_session_idle_timeout_returns_stored_value(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->assertSame(-1, $this->service->getSessionIdleTimeout($tenant));

        $this->service->update($tenant, ['session_idle_timeout' => 120]);
        $this->assertSame(120, $this->service->getSessionIdleTimeout($tenant));

        tenancy()->end();
    }

    public function test_get_mfa_grace_period_days_returns_stored_value(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->assertSame(0, $this->service->getMfaGracePeriodDays($tenant));

        $this->service->update($tenant, ['mfa_grace_period_days' => 7]);
        $this->assertSame(7, $this->service->getMfaGracePeriodDays($tenant));

        tenancy()->end();
    }

    public function test_reset_to_defaults_resets_all_fields(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->service->update($tenant, [
            'session_idle_timeout' => 120,
            'mfa_grace_period_days' => 30,
        ]);

        $record = $this->service->resetToDefaults($tenant);

        $this->assertFalse($record->mfa_required);
        $this->assertSame(0, $record->mfa_grace_period_days);
        $this->assertSame(-1, $record->session_idle_timeout);
        $this->assertSame(8, $record->password_policy['min_length']);

        tenancy()->end();
    }

    public function test_reset_to_defaults_with_specific_fields(): void
    {
        $user = $this->registerTenantUser('SP User', 'sp-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        tenancy()->initialize($tenant);

        $this->service->update($tenant, [
            'session_idle_timeout' => 120,
            'mfa_grace_period_days' => 30,
        ]);

        $record = $this->service->resetToDefaults($tenant, ['session_idle_timeout']);

        $this->assertSame(-1, $record->session_idle_timeout);
        $this->assertSame(30, $record->mfa_grace_period_days);

        tenancy()->end();
    }

    public function test_tenant_isolation(): void
    {
        $userA = $this->registerTenantUser('A', 'a-'.uniqid().'@example.com');
        $tenantA = $userA->personalTenant();

        $userB = $this->registerTenantUser('B', 'b-'.uniqid().'@example.com');
        $tenantB = $userB->personalTenant();

        tenancy()->initialize($tenantA);
        $this->service->update($tenantA, ['session_idle_timeout' => 999]);
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $resultB = $this->service->getForTenant($tenantB);
        $this->assertSame(-1, $resultB['session_idle_timeout']);
        tenancy()->end();

        tenancy()->initialize($tenantA);
        $resultA = $this->service->getForTenant($tenantA);
        $this->assertSame(999, $resultA['session_idle_timeout']);
        tenancy()->end();
    }

    protected function grantMfaAvailable(Tenant $tenant): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'mfa-test'],
            ['name' => 'MFA Test', 'is_active' => true]
        );

        Entitlement::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'code' => 'mfa_available'],
            ['name' => 'MFA Available', 'is_active' => true]
        );

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );
    }
}
