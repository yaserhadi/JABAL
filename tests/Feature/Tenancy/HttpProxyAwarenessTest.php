<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\RequestHostClassifier;
use App\Providers\AppServiceProvider;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Modules\Identity\Services\HostEnterpriseSsoInitiationService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-105 — TrustProxies from tenancy_addressing applies on HTTP boot; fail-closed spoofing.
 *
 * Host-profile contract only (excluded from default Path phpunit.xml).
 */
#[Group('host-profile-contract')]
class HttpProxyAwarenessTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    /** @var list<string> */
    private const TRUSTED_PROXIES_FIXTURE = ['127.0.0.1', '::1', '10.10.0.0/16'];

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host', [
            'TENANCY_TRUST_FORWARDED_HEADERS' => 'true',
            'TENANCY_TRUSTED_PROXIES' => implode(',', self::TRUSTED_PROXIES_FIXTURE),
            'SESSION_SECURE_COOKIE' => '',
        ]);
        parent::setUp();

        $this->applyProxyTestConfig();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    private function applyProxyTestConfig(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => true,
            'tenancy_addressing.trusted_proxies' => self::TRUSTED_PROXIES_FIXTURE,
            'session.secure' => null,
        ]);
        AppServiceProvider::configureTrustedProxiesFromConfig();
    }

    public function test_trusted_ipv4_loopback_proxy_forwarded_https_is_secure_with_secure_cookie(): void
    {
        $this->assertTrustProxiesAppliedBeforeMiddleware(self::TRUSTED_PROXIES_FIXTURE);

        $response = $this->call(
            'GET',
            'http://platform.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '127.0.0.1',
                forwardedHost: 'platform.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $response->assertOk();
        $this->assertTrue($this->lastRequest()->isSecure());
        $this->assertSame('https', $this->lastRequest()->getScheme());
        $this->assertSessionCookieSecure($response, expectSecure: true);
    }

    public function test_trusted_ipv6_loopback_proxy_forwarded_https_is_secure(): void
    {
        $response = $this->call(
            'GET',
            'http://platform.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '::1',
                forwardedHost: 'platform.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $response->assertOk();
        $this->assertTrue($this->lastRequest()->isSecure());
        $this->assertSessionCookieSecure($response, expectSecure: true);
    }

    public function test_trusted_private_network_cidr_fixture_forwarded_https_is_secure(): void
    {
        $response = $this->call(
            'GET',
            'http://platform.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '10.10.5.20',
                forwardedHost: 'platform.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $response->assertOk();
        $this->assertTrue($this->lastRequest()->isSecure());
        $this->assertSessionCookieSecure($response, expectSecure: true);
    }

    public function test_direct_http_remains_http_and_does_not_force_secure_cookie(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => false,
            'tenancy_addressing.trusted_proxies' => [],
        ]);
        AppServiceProvider::configureTrustedProxiesFromConfig();

        try {
            $response = $this->call(
                'GET',
                'http://platform.jabal.test/login',
                server: [
                    'REMOTE_ADDR' => '203.0.113.10',
                    'HTTP_HOST' => 'platform.jabal.test',
                    'SERVER_NAME' => 'platform.jabal.test',
                    'HTTPS' => 'off',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
                ]
            );

            $response->assertOk();
            $this->assertFalse($this->lastRequest()->isSecure());
            $this->assertSame('http', $this->lastRequest()->getScheme());
            $this->assertSame('platform.jabal.test', $this->lastRequest()->getHost());
            $this->assertSessionCookieSecure($response, expectSecure: false);
        } finally {
            $this->applyProxyTestConfig();
        }
    }

    public function test_untrusted_sender_cannot_spoof_proto_or_host(): void
    {
        $response = $this->call(
            'GET',
            'http://platform.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '203.0.113.50',
                forwardedHost: 'evil.example.com',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $response->assertOk();
        $this->assertFalse($this->lastRequest()->isSecure());
        $this->assertSame('http', $this->lastRequest()->getScheme());
        $this->assertSame('platform.jabal.test', $this->lastRequest()->getHost());
        $this->assertSessionCookieSecure($response, expectSecure: false);
    }

    public function test_trusted_forwarded_host_and_port_are_applied(): void
    {
        $response = $this->call(
            'GET',
            'http://127.0.0.1/login',
            server: $this->proxyServer(
                remoteAddr: '127.0.0.1',
                forwardedHost: 'platform.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '8443',
            )
        );

        $response->assertOk();
        $request = $this->lastRequest();
        $this->assertTrue($request->isSecure());
        $this->assertSame('platform.jabal.test', $request->getHost());
        $this->assertSame(8443, $request->getPort());
    }

    public function test_guest_redirect_location_uses_https_behind_trusted_proxy(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'proxytenant', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $response = $this->call(
            'GET',
            'http://proxytenant.jabal.test/dashboard',
            server: $this->proxyServer(
                remoteAddr: '127.0.0.1',
                forwardedHost: 'proxytenant.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://', $location);
        $this->assertStringContainsString('proxytenant.jabal.test', $location);
    }

    public function test_http_boot_applies_same_trust_proxies_contract_as_config(): void
    {
        $this->assertTrustProxiesAppliedBeforeMiddleware(self::TRUSTED_PROXIES_FIXTURE);

        $headers = $this->alwaysTrustHeaders();
        $portable = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO;
        $this->assertSame($portable, $headers);
        // HEADER_X_FORWARDED_AWS_ELB is a composite of FOR|PORT|PROTO — not a distinct bit.
        // Assert we did not add PREFIX (or other extras beyond the portable set).
        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_PREFIX);
    }

    public function test_behavior_remains_correct_after_config_cache(): void
    {
        $cachePath = base_path('bootstrap/cache/config.php');

        try {
            Artisan::call('config:cache');
            $this->assertFileExists($cachePath);

            /** @var array<string, mixed> $cached */
            $cached = require $cachePath;
            $this->assertTrue((bool) data_get($cached, 'tenancy_addressing.trust_forwarded_headers'));
            $this->assertContains('127.0.0.1', (array) data_get($cached, 'tenancy_addressing.trusted_proxies', []));

            // Same contract AppServiceProvider uses after a cached-config boot.
            config(['tenancy_addressing' => $cached['tenancy_addressing']]);
            config(['session.secure' => null]);
            AppServiceProvider::configureTrustedProxiesFromConfig();

            $response = $this->call(
                'GET',
                'http://platform.jabal.test/login',
                server: $this->proxyServer(
                    remoteAddr: '127.0.0.1',
                    forwardedHost: 'platform.jabal.test',
                    forwardedProto: 'https',
                    forwardedPort: '443',
                )
            );

            $response->assertOk();
            $this->assertTrue($this->lastRequest()->isSecure());
        } finally {
            if (is_file($cachePath)) {
                @unlink($cachePath);
            }
            Artisan::call('config:clear');
            $this->applyProxyTestConfig();
        }
    }

    public function test_auth_host_does_not_initialize_tenant_behind_trusted_proxy(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'authprox', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->call(
            'GET',
            'http://auth.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '127.0.0.1',
                forwardedHost: 'auth.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        );

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame(
            RequestHostClassifier::CLASS_AUTH,
            app(RequestHostClassifier::class)->classify($this->lastRequest())
        );
    }

    public function test_tenant_host_initializes_intended_tenant_behind_trusted_proxy(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'initprox', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->call(
            'GET',
            'http://initprox.jabal.test/login',
            server: $this->proxyServer(
                remoteAddr: '127.0.0.1',
                forwardedHost: 'initprox.jabal.test',
                forwardedProto: 'https',
                forwardedPort: '443',
            )
        )->assertOk();

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame((string) $tenant->id, (string) tenant('id'));
        $this->assertTrue($this->lastRequest()->isSecure());
    }

    public function test_host_sso_callback_and_handoff_urls_remain_canonical_https(): void
    {
        $addressing = app(TenantAddressingProfile::class);
        $this->assertSame('https', $addressing->canonicalScheme());
        $this->assertSame(
            'https://auth.jabal.test',
            $addressing->absoluteOriginForHost($addressing->authHost())
        );

        $ref = str_repeat('a', 32);
        $method = new \ReflectionMethod(HostEnterpriseSsoInitiationService::class, 'authHostInitiateUrl');
        $method->setAccessible(true);
        $url = $method->invoke(app(HostEnterpriseSsoInitiationService::class), $ref);

        $this->assertStringStartsWith('https://auth.jabal.test/auth/enterprise-sso/initiate?', $url);
    }

    public function test_star_proxy_list_fails_validation(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => false,
            'tenancy_addressing.trusted_proxies' => ['*'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not contain "*"');
        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_trust_true_with_empty_list_fails_validation(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => true,
            'tenancy_addressing.trusted_proxies' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty');
        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertTrustProxiesAppliedBeforeMiddleware(array $expected): void
    {
        $proxies = $this->alwaysTrustProxies();
        $this->assertSame($expected, $proxies);

        // Stock middleware must already see static state before handle().
        $middleware = app(TrustProxies::class);
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('proxies');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke($middleware));
    }

    /**
     * @return array<string, string>
     */
    private function proxyServer(
        string $remoteAddr,
        string $forwardedHost,
        string $forwardedProto,
        string $forwardedPort,
    ): array {
        return [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_HOST' => $forwardedHost,
            'SERVER_NAME' => $forwardedHost,
            'HTTPS' => 'off',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'HTTP_X_FORWARDED_HOST' => $forwardedHost,
            'HTTP_X_FORWARDED_PROTO' => $forwardedProto,
            'HTTP_X_FORWARDED_PORT' => $forwardedPort,
        ];
    }

    private function lastRequest(): Request
    {
        return request();
    }

    private function assertSessionCookieSecure($response, bool $expectSecure): void
    {
        $cookieName = (string) config('session.cookie');
        $found = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                $found = $cookie;
                break;
            }
        }

        $this->assertNotNull($found, 'Expected session cookie "'.$cookieName.'" on response.');
        $this->assertSame($expectSecure, $found->isSecure());
    }

    /**
     * @return array<int, string>|string|null
     */
    private function alwaysTrustProxies(): array|string|null
    {
        $ref = new ReflectionClass(TrustProxies::class);
        $prop = $ref->getProperty('alwaysTrustProxies');
        $prop->setAccessible(true);

        return $prop->getValue();
    }

    private function alwaysTrustHeaders(): ?int
    {
        $ref = new ReflectionClass(TrustProxies::class);
        $prop = $ref->getProperty('alwaysTrustHeaders');
        $prop->setAccessible(true);

        return $prop->getValue();
    }
}
