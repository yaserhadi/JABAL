<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\RequestHostClassifier;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Identity\Services\SsoAuthService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

class TenantAddressingHostResolutionTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();

        // Re-assert Host reserved hosts into config after boot. Mid-suite profile switches
        // (forceAddressingEnv('path') + refreshApplication in sibling Host tests) can leave
        // env()/config out of sync under phpunit.xml (no forced TENANCY_API_HOST).
        config([
            'tenancy_addressing.profile' => 'host',
            'tenancy_addressing.platform_base_domain' => 'jabal.test',
            'tenancy_addressing.platform_host' => 'platform.jabal.test',
            'tenancy_addressing.auth_host' => 'auth.jabal.test',
            'tenancy_addressing.api_host' => 'api.jabal.test',
            'tenancy_addressing.central_hosts' => [
                'localhost',
                '127.0.0.1',
                'jabal.test',
                'platform.jabal.test',
                'auth.jabal.test',
                'api.jabal.test',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    public function test_classifier_labels_reserved_and_tenant_candidate(): void
    {
        $classifier = app(RequestHostClassifier::class);

        $this->assertSame(
            RequestHostClassifier::CLASS_PLATFORM,
            $classifier->classify(Request::create('https://platform.jabal.test/login'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_AUTH,
            $classifier->classify(Request::create('https://auth.jabal.test/auth/sso/callback'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_API,
            $classifier->classify(Request::create('https://api.jabal.test/api/v1/health'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_TENANT_CANDIDATE,
            $classifier->classify(Request::create('https://acme.jabal.test/login'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_UNKNOWN,
            $classifier->classify(Request::create('https://a.b.jabal.test/login'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_UNKNOWN,
            $classifier->classify(Request::create('https://evil.example.com/login'))
        );
    }

    public function test_unknown_host_fails_closed(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'evil.example.com'])
            ->get('http://evil.example.com/login')
            ->assertNotFound();
    }

    public function test_tenant_host_resolves_via_stancl_domain_row(): void
    {
        $this->assertTrue(app(\App\Support\Tenancy\TenantAddressingProfile::class)->isHost());

        $tenant = Tenant::factory()->create(['slug' => 'acme', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->call(
            'GET',
            'http://acme.jabal.test/login',
            server: [
                'HTTP_HOST' => 'acme.jabal.test',
                'SERVER_NAME' => 'acme.jabal.test',
            ]
        )->assertOk();
    }

    public function test_inactive_tenant_rejected_before_login_page(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'paused', 'status' => 'suspended']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->withServerVariables(['HTTP_HOST' => 'paused.jabal.test'])
            ->get('http://paused.jabal.test/login')
            ->assertNotFound();
    }

    public function test_tenant_host_root_redirects_to_same_host_tenant_login(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'acme', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $expectedLogin = app(\App\Http\Auth\TenantEntryUrlResolver::class)->loginUrl($tenant);

        $response = $this->call(
            'GET',
            'http://acme.jabal.test/',
            server: [
                'HTTP_HOST' => 'acme.jabal.test',
                'SERVER_NAME' => 'acme.jabal.test',
            ]
        );

        $response->assertRedirect($expectedLogin);
        $location = (string) $response->headers->get('Location');
        $this->assertSame('acme.jabal.test', parse_url($location, PHP_URL_HOST));
        $this->assertNotSame(route('login'), $location);
    }

    public function test_tenant_host_has_no_register_route(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'noreg', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->withServerVariables(['HTTP_HOST' => 'noreg.jabal.test'])
            ->get('http://noreg.jabal.test/register')
            ->assertNotFound();
    }

    public function test_platform_host_login_is_discovery_not_tenant(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/login')
            ->assertOk();

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_dashboard_absent_on_platform_host(): void
    {
        $this->call(
            'GET',
            'http://platform.jabal.test/dashboard',
            server: ['HTTP_HOST' => 'platform.jabal.test', 'SERVER_NAME' => 'platform.jabal.test']
        )->assertNotFound();
    }

    public function test_sso_callback_absent_on_tenant_host(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'nocallback', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->withServerVariables(['HTTP_HOST' => 'nocallback.jabal.test'])
            ->get('http://nocallback.jabal.test/auth/sso/callback')
            ->assertNotFound();
    }

    public function test_host_mode_path_era_sso_routes_unregistered_and_404(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('identity.sso.redirect'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('identity.sso.callback'));

        $tenant = Tenant::factory()->create(['slug' => 'nossogate', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->withServerVariables(['HTTP_HOST' => 'nossogate.jabal.test'])
            ->get('http://nossogate.jabal.test/auth/sso/redirect')
            ->assertNotFound();

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldNotReceive('completeCallback');
        });

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/sso/callback?code=must-not-be-consumed&state=must-not-be-parsed',
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
            ]
        )->assertNotFound();
    }

    public function test_host_profile_accessor(): void
    {
        $this->assertTrue(app(TenantAddressingProfile::class)->isHost());
    }
}
