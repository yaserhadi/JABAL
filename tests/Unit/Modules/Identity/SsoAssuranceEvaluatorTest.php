<?php

namespace Tests\Unit\Modules\Identity;

use Modules\Identity\Models\TenantUser;
use Mockery;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Support\Sso\SsoAssuranceEvaluator;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-082 WS5 — IdP assurance vs Tenant MFA policy. */
class SsoAssuranceEvaluatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function sufficient_when_mfa_not_required(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $user = Mockery::mock(TenantUser::class);
        $mfa = Mockery::mock(MfaService::class);
        $mfa->shouldReceive('isMfaRequired')->with($tenant)->andReturn(false);

        $evaluator = new SsoAssuranceEvaluator($mfa);
        $this->assertTrue($evaluator->isSufficientForFullSession($tenant, $user, null));
    }

    #[Test]
    public function insufficient_when_mfa_required_without_evidence(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $user = Mockery::mock(TenantUser::class);
        $mfa = Mockery::mock(MfaService::class);
        $mfa->shouldReceive('isMfaRequired')->andReturn(true);
        $mfa->shouldReceive('userHasConfirmedMfa')->andReturn(true);

        $evaluator = new SsoAssuranceEvaluator($mfa);
        $this->assertFalse($evaluator->isSufficientForFullSession($tenant, $user, ['acr' => 'urn:example:aal1']));
    }

    #[Test]
    public function sufficient_when_amr_includes_mfa(): void
    {
        $tenant = Mockery::mock(Tenant::class);
        $user = Mockery::mock(TenantUser::class);
        $mfa = Mockery::mock(MfaService::class);
        $mfa->shouldReceive('isMfaRequired')->andReturn(true);
        $mfa->shouldReceive('userHasConfirmedMfa')->andReturn(true);

        $evaluator = new SsoAssuranceEvaluator($mfa);
        $this->assertTrue($evaluator->isSufficientForFullSession($tenant, $user, [
            'amr' => ['pwd', 'mfa'],
            'acr' => 'urn:example:aal2',
        ]));
    }
}
