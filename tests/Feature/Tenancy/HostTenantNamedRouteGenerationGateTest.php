<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Auth\TenantEntryUrlResolver;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\Support\ScansTenantHostNamedRouteGeneration;
use Tests\TestCase;

/**
 * BK-109 — Host server-side Tenant named-route generation regression gate.
 */
class HostTenantNamedRouteGenerationGateTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use ScansTenantHostNamedRouteGeneration;

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

    #[Test]
    #[Group('host-profile-contract')]
    public function host_routes_requiring_tenant_label_are_registered(): void
    {
        $names = $this->bk109HostRoutesRequiringTenantLabel();
        $this->assertNotEmpty($names);
        $this->assertContains('identity.sso.enrollments.index', $names);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function bare_redirect_route_calls_omit_tenant_label_for_host_routes(): void
    {
        $violations = $this->bk109ScanBareTenantHostNamedRouteRedirects();
        $this->assertSame(
            [],
            $violations,
            "BK-109 bare Host named-route redirects missing tenant_label:\n".json_encode($violations, JSON_PRETTY_PRINT)
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function enrollments_index_without_tenant_label_fails_url_generation(): void
    {
        $this->expectException(UrlGenerationException::class);
        route('identity.sso.enrollments.index');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function named_route_url_supplies_tenant_label_for_enrollments_index(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'bk109-'.substr(md5((string) microtime(true)), 0, 8),
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $url = app(TenantEntryUrlResolver::class)->namedRouteUrl(
            'identity.sso.enrollments.index',
            $tenant
        );

        $this->assertStringContainsString($tenant->slug.'.jabal.test', $url);
        $this->assertStringContainsString('/security/sso/enrollments', $url);
        $this->assertStringNotContainsString('?tenant=', $url);
    }
}
