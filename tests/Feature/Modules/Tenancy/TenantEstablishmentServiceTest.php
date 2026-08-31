<?php

namespace Tests\Feature\Modules\Tenancy;

use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\LegalOrganizationService;
use Modules\Tenancy\Services\TenantEstablishmentService;
use Modules\Tenancy\Support\TenantProvisioningPresenter;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** BK-115 J0-04: establishment = Active ∧ BO MFA; Active alone insufficient. */
class TenantEstablishmentServiceTest extends TestCase
{
    #[Test]
    public function active_business_owner_without_mfa_is_not_establishment_complete(): void
    {
        $user = $this->registerTenantUser('BO No MFA', 'bo-nomfa-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $org = app(LegalOrganizationService::class)->create('Org '.uniqid());
        app(LegalOrganizationService::class)->attachTenant($org, $tenant);
        $owner = app(LegalOrganizationService::class)->assignBusinessOwner($org, (string) $user->id, $tenant->id);

        $this->assertSame('active', $owner->status);

        $eval = app(TenantEstablishmentService::class)->evaluate($tenant->fresh());
        $this->assertTrue($eval['business_owner_active']);
        $this->assertFalse($eval['business_owner_mfa_satisfied']);
        $this->assertFalse($eval['complete']);
        $this->assertFalse(app(TenantEstablishmentService::class)->isEstablishmentComplete($tenant->fresh()));
    }

    #[Test]
    public function active_business_owner_with_confirmed_mfa_is_establishment_complete(): void
    {
        $user = $this->registerTenantUser('BO With MFA', 'bo-mfa-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantMfaAvailable($tenant);

        $org = app(LegalOrganizationService::class)->create('Org '.uniqid());
        app(LegalOrganizationService::class)->attachTenant($org, $tenant);
        app(LegalOrganizationService::class)->assignBusinessOwner($org, (string) $user->id, $tenant->id);

        tenancy()->initialize($tenant);
        $mfa = app(MfaService::class);
        $setup = $mfa->beginEnrollment($user);
        $code = (new Google2FA)->getCurrentOtp($setup['secret']);
        $mfa->confirmEnrollment($user, $code);
        tenancy()->end();

        $eval = app(TenantEstablishmentService::class)->evaluate($tenant->fresh());
        $this->assertTrue($eval['business_owner_active']);
        $this->assertTrue($eval['business_owner_mfa_satisfied']);
        $this->assertTrue($eval['complete']);
    }

    #[Test]
    public function assign_business_owner_still_allows_active_before_mfa(): void
    {
        $user = $this->registerTenantUser('BO Active First', 'bo-active-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $org = app(LegalOrganizationService::class)->create('Org '.uniqid());

        $owner = app(LegalOrganizationService::class)->assignBusinessOwner($org, (string) $user->id, $tenant->id);
        $this->assertSame('active', $owner->status);
        $this->assertFalse(app(TenantEstablishmentService::class)->businessOwnerMfaSatisfied($tenant, (string) $user->id));
    }

    #[Test]
    public function presenter_exposes_establishment_separate_from_provisioning(): void
    {
        $user = $this->registerTenantUser('Presenter BO', 'bo-pres-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $org = app(LegalOrganizationService::class)->create('Org '.uniqid());
        app(LegalOrganizationService::class)->attachTenant($org, $tenant);
        app(LegalOrganizationService::class)->assignBusinessOwner($org, (string) $user->id, $tenant->id);

        $presentation = app(TenantProvisioningPresenter::class)->fromTenant($tenant->fresh(['databaseConfig']));
        $this->assertArrayHasKey('establishment_complete', $presentation);
        $this->assertFalse($presentation['establishment_complete']);
        $this->assertFalse($presentation['establishment']['business_owner_mfa_satisfied']);
        // Shared tenants remain provisioning-complete on storage readiness alone (R1–R5 path separate).
        $this->assertSame(TenantProvisioningPresenter::COMPLETED, $presentation['status']);
    }

    protected function grantMfaAvailable(Tenant $tenant): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'j004-mfa-'.uniqid()],
            ['name' => 'J004 MFA', 'is_active' => true]
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
