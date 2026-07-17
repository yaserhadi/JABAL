<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-073 baseline cookie isolation — separate cookie jars per host (request-level).
 */
class TenantAddressingCookieIsolationTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;

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

    public function test_tenant_login_set_cookie_has_no_domain_attribute(): void
    {
        $user = $this->registerTenantUser('Cookie User', 'cookie-user-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenant);
        // Ensure platform subdomain uses the Handle (registration already provisioned).
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant->fresh());

        $response = $this->call(
            'POST',
            'http://'.$tenant->slug.'.jabal.test/login',
            ['email' => $user->email, 'password' => 'password'],
            server: [
                'HTTP_HOST' => $tenant->slug.'.jabal.test',
                'SERVER_NAME' => $tenant->slug.'.jabal.test',
            ]
        );

        $cookies = $response->headers->all('set-cookie');
        $this->assertNotEmpty($cookies, 'Expected Set-Cookie headers after tenant login');
        foreach ($cookies as $header) {
            $this->assertDoesNotMatchRegularExpression('/(^|;\s*)Domain=/i', $header, $header);
        }
    }

    public function test_tenant_a_cookie_does_not_authenticate_on_tenant_b_host(): void
    {
        $userA = $this->registerTenantUser('Jar A', 'jar-a-'.uniqid().'@example.com');
        $tenantA = $userA->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenantA);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenantA->fresh());

        $userB = $this->registerTenantUser('Jar B', 'jar-b-'.uniqid().'@example.com');
        $tenantB = $userB->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenantB);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenantB->fresh());

        $this->call(
            'POST',
            'http://'.$tenantA->slug.'.jabal.test/login',
            ['email' => $userA->email, 'password' => 'password'],
            server: [
                'HTTP_HOST' => $tenantA->slug.'.jabal.test',
                'SERVER_NAME' => $tenantA->slug.'.jabal.test',
            ]
        );

        $response = $this->call(
            'GET',
            'http://'.$tenantB->slug.'.jabal.test/dashboard',
            server: [
                'HTTP_HOST' => $tenantB->slug.'.jabal.test',
                'SERVER_NAME' => $tenantB->slug.'.jabal.test',
            ]
        );

        $this->assertTrue(
            in_array($response->status(), [302, 401, 403, 404], true),
            'Expected non-auth success, got '.$response->status()
        );
        $this->assertNotSame(200, $response->status());
    }
}
