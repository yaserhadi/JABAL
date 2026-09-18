<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-125 Wave 4 — Apex Tenant discovery ownership (CONFLICT-C).
 */
class ApexDiscoveryOwnershipTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAddressingEnv();
        parent::tearDown();
    }

    public function test_path_apex_login_discovers_tenant_and_redirects_to_path_login(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $user = $this->registerTenantUser('Path Discover', 'path-disc-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));

        $this->post(route('login'), ['slug' => $tenant->slug])
            ->assertRedirect($this->tenantLoginRedirectUri($tenant));

        $this->assertGuest('web');
        $this->assertGuest('platform');
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_path_host_apex_login_discovers_tenant_and_redirects_to_host_login(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $user = $this->registerTenantUser('Host Discover', 'host-disc-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        app(\Modules\Tenancy\Services\TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->get('http://jabal.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));

        $response = $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->post('http://jabal.test/login', ['slug' => $tenant->slug]);

        $response->assertRedirect($this->tenantLoginRedirectUri($tenant));
        $this->assertStringContainsString($tenant->slug.'.jabal.test/login', $response->headers->get('Location'));
        $this->assertGuest('web');
        $this->assertGuest('platform');
    }

    public function test_path_unknown_tenant_discovery_fails_safely(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->from(route('login'))
            ->post(route('login'), ['slug' => 'does-not-exist-'.uniqid()])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors();

        $this->assertGuest('web');
    }

    public function test_path_host_platform_login_remains_operator_only(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Login')
                ->where('entryPlane', 'platform_operator')
            );
    }

    public function test_path_host_apex_root_renders_landing_not_login_redirect(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->get('http://jabal.test/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Landing')
                ->has('appName')
            );

        $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->get('http://jabal.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_path_apex_root_renders_landing(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Landing'));

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_path_platform_login_remains_under_platform_prefix(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->get(route('platform.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Login'));

        $this->assertSame('/platform/login', parse_url(route('platform.login'), PHP_URL_PATH));
    }
}
