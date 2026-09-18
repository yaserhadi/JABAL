<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-125 Wave 5 — Platform URI surface (CONFLICT-D).
 */
class PlatformUriSurfaceTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAddressingEnv();
        parent::tearDown();
    }

    public function test_path_platform_uris_use_platform_prefix(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->assertSame('/platform/login', parse_url(route('platform.login'), PHP_URL_PATH));
        $this->assertSame('/platform/dashboard', parse_url(route('platform.dashboard'), PHP_URL_PATH));
        $this->assertSame('/platform/tenants', parse_url(route('platform.tenants.index'), PHP_URL_PATH));
        $this->assertSame('/platform/settings', parse_url(route('platform.settings.index'), PHP_URL_PATH));
    }

    public function test_path_host_platform_uris_are_host_root(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $this->assertSame('/login', parse_url(route('platform.login'), PHP_URL_PATH));
        $this->assertSame('/dashboard', parse_url(route('platform.dashboard'), PHP_URL_PATH));
        $this->assertSame('/tenants', parse_url(route('platform.tenants.index'), PHP_URL_PATH));
        $this->assertSame('/settings', parse_url(route('platform.settings.index'), PHP_URL_PATH));

        $this->assertStringContainsString('platform.jabal.test', route('platform.tenants.index'));
    }

    public function test_path_host_has_no_platform_prefix_compatibility_surface(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/platform/tenants')
            ->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/platform/settings')
            ->assertNotFound();
    }

    public function test_path_successful_login_lands_on_dashboard(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $user = PlatformUser::create([
            'name' => 'Wave5 Path',
            'email' => 'w5-path-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($user);

        $this->post(route('platform.login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('platform.dashboard', absolute: false));

        $this->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Dashboard'));

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_path_host_successful_login_lands_on_dashboard(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $user = PlatformUser::create([
            'name' => 'Wave5 Host',
            'email' => 'w5-host-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($user);

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->post('http://platform.jabal.test/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('platform.dashboard', absolute: false));

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Dashboard'));

        $this->assertFalse(tenancy()->initialized);
    }

    public function test_path_host_root_guest_redirects_to_login(): void
    {
        $this->forceAddressingEnv('path_host');
        $this->refreshApplication();

        $this->withServerVariables(['HTTP_HOST' => 'platform.jabal.test'])
            ->get('http://platform.jabal.test/')
            ->assertRedirect(route('platform.login'));
    }
}
