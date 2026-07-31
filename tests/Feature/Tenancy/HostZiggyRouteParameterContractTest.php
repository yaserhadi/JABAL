<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-107 — Host Ziggy / Laravel route-parameter contract (tenant_label).
 *
 * Path parameter assertions are intentionally omitted: current UAT delivery is Host-only;
 * Path is not revived for test convenience.
 */
#[Group('host-profile-contract')]
class HostZiggyRouteParameterContractTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    public function test_host_tenant_routes_require_tenant_label_domain_parameter(): void
    {
        $this->assertTrue(app(TenantAddressingProfile::class)->isHost());

        foreach ([
            'tenant.login',
            'tenant.login.submit',
            'tenant.logout',
            'dashboard',
            'identity.security-settings.show',
            'identity.sso.update',
            'identity.sso.enrollments.index',
            'identity.sso.enrollment.invitation',
            'identity.enterprise-sso.start',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Expected registered route [{$name}]");
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains(
                'tenant_label',
                $route->parameterNames(),
                "Host route [{$name}] must declare domain parameter tenant_label",
            );
            $this->assertNotContains(
                'tenant',
                $route->parameterNames(),
                "Host route [{$name}] must not use Path parameter tenant",
            );
        }
    }

    public function test_platform_and_auth_host_routes_remain_without_tenant_label(): void
    {
        $this->assertTrue(Route::has('platform.login'));
        $this->assertTrue(Route::has('login'));

        $platformLogin = Route::getRoutes()->getByName('platform.login');
        $this->assertNotNull($platformLogin);
        $this->assertNotContains('tenant_label', $platformLogin->parameterNames());

        // Auth Host enterprise callback (BK-082) — not a Tenant Host {tenant_label} route.
        if (Route::has('identity.enterprise-sso.callback')) {
            $callback = Route::getRoutes()->getByName('identity.enterprise-sso.callback');
            $this->assertNotNull($callback);
            $this->assertNotContains('tenant_label', $callback->parameterNames());
        }
    }

    public function test_php_named_route_url_places_label_in_hostname(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'acme-uat',
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $url = app(TenantEntryUrlResolver::class)->namedRouteUrl('tenant.login.submit', $tenant);

        $this->assertStringContainsString('acme-uat.jabal.test', $url);
        $this->assertStringEndsWith('/login', parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertStringNotContainsString('tenant=', $url);
        $this->assertStringNotContainsString('{tenant_label}', $url);
    }
}
