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

    public function test_platform_login_cookie_is_absent_on_tenant_host(): void
    {
        $admin = \App\Models\PlatformUser::create([
            'name' => 'Plat Cookie Admin',
            'email' => 'plat-cookie-'.uniqid().'@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($admin);

        $platformLogin = $this->call(
            'POST',
            'https://platform.jabal.test/platform/login',
            ['email' => $admin->email, 'password' => 'password'],
            server: [
                'HTTP_HOST' => 'platform.jabal.test',
                'SERVER_NAME' => 'platform.jabal.test',
            ]
        );
        $platformLogin->assertRedirect();
        foreach ($platformLogin->headers->all('set-cookie') as $header) {
            $this->assertDoesNotMatchRegularExpression('/(^|;\s*)Domain=/i', $header, $header);
        }

        $user = $this->registerTenantUser('Plat Jar', 'plat-jar-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant->fresh());

        // Same PHPUnit cookie jar may forward cookies; Platform guard must not become Tenant web auth.
        $tenantDash = $this->call(
            'GET',
            'https://'.$tenant->slug.'.jabal.test/dashboard',
            server: [
                'HTTP_HOST' => $tenant->slug.'.jabal.test',
                'SERVER_NAME' => $tenant->slug.'.jabal.test',
            ]
        );

        $this->assertNotSame(200, $tenantDash->status());
        $this->assertGuest('web');
    }

    public function test_tenant_cookie_does_not_authenticate_on_platform_host(): void
    {
        $user = $this->registerTenantUser('Ten Jar', 'ten-jar-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant->fresh());

        $this->call(
            'POST',
            'https://'.$tenant->slug.'.jabal.test/login',
            ['email' => $user->email, 'password' => 'password'],
            server: [
                'HTTP_HOST' => $tenant->slug.'.jabal.test',
                'SERVER_NAME' => $tenant->slug.'.jabal.test',
            ]
        );

        $platform = $this->call(
            'GET',
            'https://platform.jabal.test/platform/tenants',
            server: [
                'HTTP_HOST' => 'platform.jabal.test',
                'SERVER_NAME' => 'platform.jabal.test',
            ]
        );

        $this->assertTrue(
            in_array($platform->status(), [302, 401, 403], true),
            'Expected Platform auth challenge, got '.$platform->status()
        );
        $this->assertGuest('platform');
    }

    public function test_callback_host_does_not_reuse_tenant_session_cookie(): void
    {
        $user = $this->registerTenantUser('Cb Jar', 'cb-jar-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $this->assertInstanceOf(Tenant::class, $tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant->fresh());

        $login = $this->call(
            'POST',
            'https://'.$tenant->slug.'.jabal.test/login',
            ['email' => $user->email, 'password' => 'password'],
            server: [
                'HTTP_HOST' => $tenant->slug.'.jabal.test',
                'SERVER_NAME' => $tenant->slug.'.jabal.test',
            ]
        );
        $login->assertRedirect();

        $cookies = $login->headers->all('set-cookie');
        $this->assertNotEmpty($cookies);
        foreach ($cookies as $header) {
            $this->assertDoesNotMatchRegularExpression('/(^|;\s*)Domain=/i', $header, $header);
        }

        // End Tenant web session in this process so Auth Host cannot "reuse" it via shared test state.
        auth('web')->logout();
        $this->flushSession();

        // Auth Host Path-era callback is unregistered on Host (BK-103) — must 404 without auth.
        $callback = $this->call(
            'GET',
            'https://auth.jabal.test/auth/sso/callback?code=x&state=y',
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
            ]
        );

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('identity.sso.callback'));
        $this->assertSame(404, $callback->status());
        $this->assertGuest('web');
    }
}
