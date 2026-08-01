<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\Support\ScansTenantHostNamedRouteGeneration;
use Tests\TestCase;

/**
 * BK-109 — Path profile: Host-style tenant_label scan is empty; Path routes use {tenant}.
 * Confirms Path registration retained without treating as UAT-active Host contract.
 */
class PathTenantNamedRouteGenerationGateTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use ScansTenantHostNamedRouteGeneration;

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
    #[Group('path-profile-contract')]
    public function path_profile_does_not_register_host_tenant_label_routes(): void
    {
        $hostLabelRoutes = $this->bk109HostRoutesRequiringTenantLabel();
        $this->assertSame(
            [],
            $hostLabelRoutes,
            'Path profile must not register Host {tenant_label} routes; found: '.implode(', ', $hostLabelRoutes)
        );
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function path_profile_registers_tenant_param_routes_when_supported(): void
    {
        $tenantParamRoutes = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || $name === '') {
                continue;
            }
            if (in_array('tenant', $route->parameterNames(), true)) {
                $tenantParamRoutes[] = $name;
            }
        }
        sort($tenantParamRoutes);
        $this->assertNotEmpty(
            $tenantParamRoutes,
            'Path profile is expected to retain {tenant} named routes in this repository'
        );
        // Workforce enrollments admin routes are Host-onTenantHost only (not Path-registered).
        $this->assertNotContains('identity.sso.enrollments.index', $tenantParamRoutes);
    }
}
