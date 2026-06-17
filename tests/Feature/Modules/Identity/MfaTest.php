<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** AVM — tenant-layer MFA enrollment and verification. */
class MfaTest extends TestCase
{
    public function test_mfa_enrollment_and_verification_on_tenant_layer(): void
    {
        $user = $this->registerTenantUser('MFA User', 'mfa-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantMfaAvailable($tenant);

        tenancy()->initialize($tenant);

        $service = app(MfaService::class);
        $setup = $service->beginEnrollment($user);

        $code = (new Google2FA)->getCurrentOtp($setup['secret']);
        $recovery = $service->confirmEnrollment($user, $code);

        $this->assertCount(8, $recovery);
        $this->assertTrue($service->userHasConfirmedMfa($user));
        $this->assertDatabaseHas('user_mfa', [
            'user_id' => $user->id,
        ], 'tenant');

        $freshCode = (new Google2FA)->getCurrentOtp($setup['secret']);
        $this->assertTrue($service->verifyChallenge($user, $freshCode));

        tenancy()->end();
    }

    public function test_mfa_unavailable_without_entitlement(): void
    {
        $user = $this->registerTenantUser('No MFA', 'nomfa-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        tenancy()->initialize($tenant);
        $service = app(MfaService::class);
        $this->assertFalse($service->isMfaAvailable($tenant));
        tenancy()->end();
    }

    public function test_no_central_auth_artifacts_in_mfa_path(): void
    {
        $this->assertFalse(DB::connection('central')->getSchemaBuilder()->hasTable('user_mfa'));
        $this->assertTrue(DB::connection('tenant')->getSchemaBuilder()->hasTable('user_mfa'));
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
