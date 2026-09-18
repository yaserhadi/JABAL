<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Modules\Identity\Services\SsoAuthService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-125 — Auth plane single-discriminator contract.
 *
 * PATH: Auth path prefix on Apex ({apex}/auth/…).
 * PATH_HOST: Auth Host is the discriminator — root-relative routes (never {auth-host}/auth/*).
 */
class AuthPlaneAddressingContractTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAddressingEnv();
        parent::tearDown();
    }

    #[Test]
    public function path_auth_callback_uses_apex_auth_path_prefix(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->assertTrue(Route::has('identity.sso.callback'));
        $url = URL::route('identity.sso.callback', absolute: true);

        $this->assertStringContainsString('/auth/sso/callback', parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertSame(
            app(TenantAddressingProfile::class)->apexHost(),
            parse_url($url, PHP_URL_HOST)
        );
    }

    #[Test]
    public function path_host_auth_routes_are_root_relative_on_auth_host(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $profile = app(TenantAddressingProfile::class);
        $this->assertTrue($profile->isPathHost());

        $callback = URL::route('identity.enterprise-sso.callback', absolute: true);
        $initiate = URL::route('identity.enterprise-sso.initiate', absolute: true);

        $this->assertSame($profile->authHost(), parse_url($callback, PHP_URL_HOST));
        $this->assertSame($profile->authHost(), parse_url($initiate, PHP_URL_HOST));

        $this->assertSame('/enterprise-sso/callback', parse_url($callback, PHP_URL_PATH));
        $this->assertSame('/enterprise-sso/initiate', parse_url($initiate, PHP_URL_PATH));

        $this->assertSame($callback, app(SsoAuthService::class)->callbackRedirectUri());

        $authHost = $profile->authHost();
        $authHostUris = collect(Route::getRoutes())
            ->filter(static fn ($route): bool => $route->getDomain() === $authHost)
            ->map(static fn ($route): string => ltrim($route->uri(), '/'))
            ->values()
            ->all();

        $this->assertContains('enterprise-sso/callback', $authHostUris);
        $this->assertContains('enterprise-sso/initiate', $authHostUris);
        $this->assertContains('enterprise-sso/backchannel-logout', $authHostUris);

        foreach ($authHostUris as $uri) {
            $this->assertFalse(
                str_starts_with($uri, 'auth/'),
                "Auth Host must not register /auth/* path (got [{$uri}])."
            );
        }
    }

    #[Test]
    public function path_host_auth_host_must_not_expose_double_addressed_auth_prefix(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $authHost = app(TenantAddressingProfile::class)->authHost();

        $double = Request::create(
            'https://'.$authHost.'/auth/enterprise-sso/callback',
            'GET',
            server: [
                'HTTP_HOST' => $authHost,
                'SERVER_NAME' => $authHost,
                'HTTPS' => 'on',
            ]
        );

        try {
            Route::getRoutes()->match($double);
            $this->fail('Double-addressed Auth Host /auth/* must not match a route.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $canonical = Request::create(
            'https://'.$authHost.'/enterprise-sso/callback',
            'GET',
            server: [
                'HTTP_HOST' => $authHost,
                'SERVER_NAME' => $authHost,
                'HTTPS' => 'on',
            ]
        );

        $matched = Route::getRoutes()->match($canonical);
        $this->assertSame('identity.enterprise-sso.callback', $matched->getName());
        $this->assertSame('enterprise-sso/callback', $matched->uri());

        // HTTP: double-addressed path remains absent (404). Callback service may also 404
        // without OIDC params — that is fail-closed handling, not absence of the route.
        $this->call(
            'GET',
            'https://'.$authHost.'/auth/enterprise-sso/callback',
            server: [
                'HTTP_HOST' => $authHost,
                'SERVER_NAME' => $authHost,
                'HTTPS' => 'on',
            ]
        )->assertNotFound();
    }
}
