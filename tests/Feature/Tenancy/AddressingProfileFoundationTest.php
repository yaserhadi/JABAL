<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-125 — dual-profile addressing foundation (PATH | PATH_HOST only).
 */
class AddressingProfileFoundationTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAddressingEnv();
        parent::tearDown();
    }

    public function test_path_profile_is_first_class_and_is_not_path_host(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $profile = app(TenantAddressingProfile::class);

        $this->assertSame(TenantAddressingProfile::PROFILE_PATH, $profile->profile());
        $this->assertTrue($profile->isPath());
        $this->assertFalse($profile->isPathHost());
        $profile->assertValidConfiguration();
    }

    public function test_path_host_profile_is_first_class(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $profile = app(TenantAddressingProfile::class);

        $this->assertSame(TenantAddressingProfile::PROFILE_PATH_HOST, $profile->profile());
        $this->assertTrue($profile->isPathHost());
        $this->assertFalse($profile->isPath());
        $profile->assertValidConfiguration();
    }

    public function test_legacy_host_profile_is_rejected(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        config(['tenancy_addressing.profile' => 'host']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host');

        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_invalid_profile_fails_closed(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        config(['tenancy_addressing.profile' => 'not_a_profile']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid TENANCY_ADDRESSING_PROFILE');

        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_host_redirect_is_rejected_not_a_third_profile(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        config(['tenancy_addressing.profile' => 'host_redirect']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host_redirect');

        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_path_host_without_base_domain_fails_closed(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        config(['tenancy_addressing.platform_base_domain' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TENANT_PLATFORM_BASE_DOMAIN');

        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_path_tenant_entry_url_uses_path_discriminator(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $user = $this->registerTenantUser('Path Entry', 'path-entry-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $resolver = app(\App\Http\Auth\TenantEntryUrlResolver::class);

        $entry = $resolver->entryUrl($tenant);
        $login = $resolver->loginUrl($tenant);

        $this->assertStringContainsString('/t/'.$tenant->slug, $entry);
        $this->assertStringContainsString('/t/'.$tenant->slug.'/login', $login);
        $this->assertStringNotContainsString($tenant->slug.'.', parse_url($entry, PHP_URL_HOST) ?: '');
    }

    public function test_path_host_tenant_entry_url_uses_host_discriminator(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $user = $this->registerTenantUser('Host Entry', 'host-entry-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $resolver = app(\App\Http\Auth\TenantEntryUrlResolver::class);

        $entry = $resolver->entryUrl($tenant);
        $login = $resolver->loginUrl($tenant);

        $this->assertSame(
            'https://'.$tenant->slug.'.jabal.test',
            $entry
        );
        $this->assertSame($entry.'/login', $login);
        $this->assertStringNotContainsString('/t/', $entry);
        $this->assertStringNotContainsString('/t/', $login);
    }

    public function test_path_host_platform_login_is_root_not_double_addressed(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $login = route('platform.login', absolute: false);

        $this->assertSame('/login', $login);
        $this->assertStringNotContainsString('/platform/login', $login);
    }

    public function test_path_platform_login_uses_path_prefix(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $login = route('platform.login', absolute: false);

        $this->assertSame('/platform/login', $login);
    }

    public function test_path_and_path_host_do_not_silently_share_tenant_route_shape(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();
        $pathProfile = app(TenantAddressingProfile::class);
        $this->assertTrue($pathProfile->isPath());

        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();
        $hostProfile = app(TenantAddressingProfile::class);
        $this->assertTrue($hostProfile->isPathHost());
        $this->assertFalse($hostProfile->isPath());
    }
}
