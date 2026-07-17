<?php

namespace Tests\Feature\Modules\Identity;

use App\Http\Auth\TenantEntryUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantEntryUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_on_slug_dashboard_redirects_to_tenant_login_and_remembers_intended(): void
    {
        $user = $this->registerTenantUser('Guest Redirect', 'guest-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $dashboard = app(TenantEntryUrlResolver::class)->dashboardUrl($tenant);

        $response = $this->get($dashboard);

        $response->assertRedirect($this->tenantLoginRedirectUri($tenant));
        $this->assertSame($dashboard, session('url.intended'));
    }

    public function test_guest_on_uuid_path_redirects_to_canonical_slug_login(): void
    {
        $user = $this->registerTenantUser('Uuid Guest', 'uuid-guest-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();

        $response = $this->get('/t/'.$tenant->id.'/workspaces');

        $response->assertRedirect($this->tenantLoginRedirectUri($tenant));
    }

    public function test_login_returns_to_safe_intended_destination(): void
    {
        $email = 'intended-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Intended User', $email);
        $tenant = $user->homeTenant();
        $target = $this->tenantNamedRouteUrl('workspaces.index', $tenant);

        $this->get($target)->assertRedirect();

        $response = $this->post('/t/'.$tenant->slug.'/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertRedirect($target);
        $this->assertAuthenticated('web');
    }

    public function test_external_intended_url_falls_back_to_dashboard(): void
    {
        $email = 'ext-intended-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Ext Intended', $email);
        $tenant = $user->homeTenant();

        $this->withSession(['url.intended' => 'https://evil.example/phish'])
            ->post('/t/'.$tenant->slug.'/login', [
                'email' => $email,
                'password' => 'password',
            ])
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));
    }

    public function test_cross_tenant_intended_url_falls_back_to_dashboard(): void
    {
        $email = 'cross-intended-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Cross Intended', $email);
        $tenant = $user->homeTenant();
        $otherUser = $this->registerTenantUser('Other Org User', 'other-'.uniqid().'@example.com');
        $other = $otherUser->homeTenant();

        $this->withSession(['url.intended' => $this->tenantDashboardRedirectUri($other)])
            ->post('/t/'.$tenant->slug.'/login', [
                'email' => $email,
                'password' => 'password',
            ])
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));
    }

    public function test_logout_redirects_to_tenant_login_when_tip_known(): void
    {
        $user = $this->registerTenantUser('Logout Tip', 'logout-tip-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();

        $response = $this->actingAsTenantUser($user, $tenant, 'web')
            ->withSession(['tenant_id' => $tenant->id])
            ->post('/logout');

        $this->assertGuest();
        $response->assertRedirect($this->tenantLoginRedirectUri($tenant));
    }

    public function test_logout_clears_intended_and_falls_back_to_central_without_tip(): void
    {
        $user = $this->registerTenantUser('Logout Central', 'logout-central-'.uniqid().'@example.com');

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $response = $this->actingAs($user, 'web')
            ->withSession(['url.intended' => url('/t/something/dashboard')])
            ->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $this->assertNull(session('url.intended'));
    }

    public function test_unknown_tenant_key_is_not_found(): void
    {
        $this->get('/t/does-not-exist-'.uniqid().'/login')->assertNotFound();
    }

    public function test_inactive_tenant_login_remains_not_found(): void
    {
        $user = $this->registerTenantUser('Inactive Tenant', 'inactive-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $tenant->update(['status' => 'suspended']);

        $this->get('/t/'.$tenant->slug.'/login')->assertNotFound();
        $dashboard = $this->get('/t/'.$tenant->slug.'/dashboard');
        if (app(\App\Support\Tenancy\TenantAddressingProfile::class)->isHost()) {
            $dashboard->assertNotFound();
        } else {
            // Path profile preserves the known inactive Tenant login redirect contract.
            $dashboard->assertRedirect($this->tenantLoginRedirectUri($tenant));
        }
    }

    public function test_platform_guest_still_goes_to_platform_login(): void
    {
        $this->get('/platform/settings')->assertRedirect(route('platform.login'));
    }

    public function test_api_unauthenticated_returns_json_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_resolver_rejects_cross_tenant_and_external_urls(): void
    {
        $user = $this->registerTenantUser('Resolver Safe', 'resolver-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        $resolver = app(TenantEntryUrlResolver::class);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $this->assertTrue($resolver->isSafeIntendedUrl(
            $request,
            $tenant,
            $this->tenantNamedRouteUrl('workspaces.index', $tenant).'?tab=1'
        ));
        $this->assertTrue($resolver->isSafeIntendedUrl(
            $request,
            $tenant,
            $this->tenantDashboardRedirectUri($tenant)
        ));
        $this->assertFalse($resolver->isSafeIntendedUrl($request, $tenant, 'https://evil.test/t/'.$tenant->slug.'/dashboard'));
        $this->assertFalse($resolver->isSafeIntendedUrl(
            $request,
            $tenant,
            app(TenantEntryUrlResolver::class)->dashboardUrl(
                \Modules\Tenancy\Models\Tenant::factory()->create(['slug' => 'other-slug'])
            )
        ));
    }
}
