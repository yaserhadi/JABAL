<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BK-115 Option A: entitlement mfa_required must not force ordinary-user MFA.
 * Ordinary require = mfa_available ∧ Tenant policy mfa_required.
 */
class MfaOrdinaryRequireOptionATest extends TestCase
{
    #[Test]
    public function entitlement_mfa_required_alone_does_not_force_ordinary_require(): void
    {
        $user = $this->registerTenantUser('OptA User', 'opta-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantEntitlements($tenant, ['mfa_available', 'mfa_required']);

        tenancy()->initialize($tenant);
        $mfa = app(MfaService::class);
        $this->assertTrue($mfa->isMfaAvailable($tenant));
        // Policy default false → not required despite entitlement mfa_required row.
        $this->assertFalse($mfa->isMfaRequired($tenant));
        tenancy()->end();
    }

    #[Test]
    public function available_plus_policy_requires_ordinary_mfa(): void
    {
        $user = $this->registerTenantUser('OptA Policy', 'optap-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantEntitlements($tenant, ['mfa_available']);

        tenancy()->initialize($tenant);
        app(SecurityPolicyService::class)->update($tenant, ['mfa_required' => true]);
        $mfa = app(MfaService::class);
        $this->assertTrue($mfa->isMfaAvailable($tenant));
        $this->assertTrue($mfa->isMfaRequired($tenant));
        tenancy()->end();
    }

    #[Test]
    public function unavailable_mfa_never_required_even_with_policy_row(): void
    {
        $user = $this->registerTenantUser('OptA Unavail', 'optau-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        tenancy()->initialize($tenant);
        // Direct row — policy update would 403 without entitlement.
        \Modules\Identity\Models\TenantSecurityPolicy::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'mfa_required' => true,
                'mfa_grace_period_days' => 0,
                'password_policy' => [
                    'min_length' => 8,
                    'require_uppercase' => false,
                    'require_number' => false,
                    'require_special' => false,
                ],
            ]
        );
        $mfa = app(MfaService::class);
        $this->assertFalse($mfa->isMfaAvailable($tenant));
        $this->assertFalse($mfa->isMfaRequired($tenant));
        tenancy()->end();
    }

    /**
     * @param  list<string>  $codes
     */
    protected function grantEntitlements(Tenant $tenant, array $codes): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'opta-'.uniqid()],
            ['name' => 'Option A Plan', 'is_active' => true]
        );
        foreach ($codes as $code) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => $code],
                ['name' => $code, 'is_active' => true]
            );
        }
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
