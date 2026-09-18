<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-125 Wave 6 — APP_URL = Apex deployment root; plane URLs via addressing.
 */
class AppUrlOwnershipTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAddressingEnv();
        parent::tearDown();
    }

    public function test_path_app_url_is_apex_and_plane_urls_are_correct(): void
    {
        $this->forceAddressingEnv('path', [
            'APP_URL' => 'https://example.com',
            'TENANCY_PLATFORM_HOST' => 'example.com',
            'TENANT_PLATFORM_BASE_DOMAIN' => 'example.com',
            'TENANCY_CANONICAL_SCHEME' => 'https',
        ]);
        $this->refreshApplication();

        $this->assertSame('https://example.com', config('app.url'));
        $this->assertSame('example.com', app(TenantAddressingProfile::class)->apexHost());

        $resolver = app(TenantEntryUrlResolver::class);
        $this->assertSame('https://example.com', $resolver->apexOrigin());
        $this->assertSame('https://example.com/login', $resolver->apexUrl('/login'));
        $this->assertSame('https://example.com/register', $resolver->apexUrl('/register'));

        $this->assertSame('/platform/dashboard', parse_url(route('platform.dashboard'), PHP_URL_PATH));
        $this->assertStringStartsWith('https://example.com/platform/dashboard', route('platform.dashboard'));

        $user = $this->registerTenantUser('Path Url', 'path-url-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();

        $this->assertSame(
            'https://example.com/t/'.$tenant->slug.'/login',
            $resolver->loginUrl($tenant)
        );
        $this->assertSame(
            'https://example.com/invitations/tokentokentokentokentokentokentokentokentokentokentokentoken12',
            $resolver->apexUrl('/invitations/tokentokentokentokentokentokentokentokentokentokentokentoken12')
        );
    }

    public function test_path_host_app_url_is_apex_not_platform_host(): void
    {
        $this->forceAddressingEnv('path_host', [
            'APP_URL' => 'https://example.com',
            'TENANT_PLATFORM_BASE_DOMAIN' => 'example.com',
            'TENANCY_PLATFORM_HOST' => 'platform.example.com',
            'TENANCY_AUTH_HOST' => 'auth.example.com',
            'TENANCY_CANONICAL_SCHEME' => 'https',
            'TENANCY_CENTRAL_HOSTS' => 'example.com,platform.example.com,auth.example.com',
        ]);
        $this->refreshApplication();

        $profile = app(TenantAddressingProfile::class);
        $this->assertSame('https://example.com', config('app.url'));
        $this->assertSame('example.com', $profile->apexHost());
        $this->assertSame('platform.example.com', $profile->platformHost());
        $this->assertNotSame(parse_url(config('app.url'), PHP_URL_HOST), $profile->platformHost());

        $resolver = app(TenantEntryUrlResolver::class);
        $this->assertSame('https://example.com/login', $resolver->apexUrl('/login'));
        $this->assertSame('/dashboard', parse_url(route('platform.dashboard'), PHP_URL_PATH));
        $this->assertStringContainsString('platform.example.com', route('platform.dashboard'));
        $this->assertStringNotContainsString('platform.example.com/platform/', route('platform.dashboard'));

        $user = $this->registerTenantUser('Host Url', 'host-url-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        app(\Modules\Tenancy\Services\TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->assertSame(
            'https://'.$tenant->slug.'.example.com/login',
            $resolver->loginUrl($tenant)
        );

        $invite = $resolver->namedRouteUrl(
            'identity.sso.enrollment.invitation',
            $tenant,
            ['enrollment_token' => str_repeat('a', 64)]
        );
        $this->assertStringStartsWith('https://'.$tenant->slug.'.example.com/security/sso/enrollment/invitations/', $invite);
    }

    public function test_out_of_request_apex_and_tenant_urls_do_not_use_platform_as_app_url(): void
    {
        $this->forceAddressingEnv('path_host', [
            'APP_URL' => 'https://example.com',
            'TENANT_PLATFORM_BASE_DOMAIN' => 'example.com',
            'TENANCY_PLATFORM_HOST' => 'platform.example.com',
            'TENANCY_AUTH_HOST' => 'auth.example.com',
            'TENANCY_CANONICAL_SCHEME' => 'https',
        ]);
        $this->refreshApplication();

        // Simulate CLI/queue: no HTTP request binding for UrlGenerator root confusion.
        if ($this->app->bound('request')) {
            $this->app->instance('request', Request::create('https://platform.example.com/tenants', 'GET'));
        }

        $exit = Artisan::call('about');
        $this->assertSame(0, $exit);

        $resolver = app(TenantEntryUrlResolver::class);
        $this->assertSame('https://example.com', config('app.url'));
        $this->assertSame('https://example.com/register', $resolver->apexUrl('/register'));

        $user = $this->registerTenantUser('Cli Url', 'cli-url-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        app(\Modules\Tenancy\Services\TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->assertSame(
            'https://'.$tenant->slug.'.example.com/dashboard',
            $resolver->dashboardUrl($tenant)
        );
    }
}
