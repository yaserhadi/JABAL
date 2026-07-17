<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tenancy\TenantAddressingProfile;

/**
 * Path-profile Enterprise SSO HTTP surfaces are negatively gated under Host (BK-073 / BK-082).
 * Host negative-gate coverage lives in TenantAddressingHostResolutionTest.
 */
trait SkipsPathEnterpriseSsoUnderHostProfile
{
    protected function skipPathEnterpriseSsoWhenHostProfile(): void
    {
        if (app(TenantAddressingProfile::class)->isHost()) {
            $this->markTestSkipped(
                'Path Enterprise SSO positive surface; Host profile negative gate is attested in TenantAddressingHostResolutionTest (BK-073). Positive Host SSO is BK-082.'
            );
        }
    }
}
