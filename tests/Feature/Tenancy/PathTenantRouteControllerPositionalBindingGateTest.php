<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\Support\ScansTenantRouteControllerPositionalBinding;
use Tests\TestCase;

/**
 * BK-108 — Path-profile route/controller positional scalar binding gate.
 *
 * Metadata-only: does not use RefreshDatabase (routes register at boot).
 */
#[Group('path-profile-contract')]
class PathTenantRouteControllerPositionalBindingGateTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use ScansTenantRouteControllerPositionalBinding;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('path');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    #[Test]
    public function path_tenant_routes_do_not_expose_positional_scalar_injection(): void
    {
        $violations = $this->scanTenantAddressedControllerRoutes();
        $this->assertSame(
            [],
            $violations,
            "BK-108 positional binding violations (Path):\n".$this->formatBk108PositionalViolations($violations)
        );
    }
}
