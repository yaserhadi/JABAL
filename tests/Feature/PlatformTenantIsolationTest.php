<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

class PlatformTenantIsolationTest extends TestCase
{
    public function test_platform_user_cannot_access_tenant_dashboard_without_membership(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Operator',
            'email' => 'operator-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $tenantUser = $this->registerTenantUser('Tenant Member', 'member-'.uniqid().'@tenant.test');
        $tenant = $tenantUser->personalTenant();

        $response = $this->actingAs($platformUser, 'platform')
            ->get('/t/'.$tenant->id.'/dashboard');

        $response->assertStatus(403);
    }

    public function test_tenant_user_cannot_access_platform_settings(): void
    {
        $tenantUser = $this->registerTenantUser('Tenant Only', 'only-'.uniqid().'@tenant.test');
        $tenant = $tenantUser->personalTenant();
        tenancy()->initialize($tenant);

        $response = $this->actingAs($tenantUser, 'web')
            ->get('/platform/settings');

        $response->assertRedirect(route('platform.login'));
    }

    public function test_platform_login_redirects_to_platform_settings(): void
    {
        PlatformUser::create([
            'name' => 'Admin',
            'email' => 'plat-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->post('/platform/login', [
            'email' => PlatformUser::query()->latest('created_at')->value('email'),
            'password' => 'password',
        ]);

        $response->assertRedirect(route('platform.settings.index', absolute: false));
        $this->assertAuthenticated('platform');
    }

    public function test_authenticated_platform_user_visiting_platform_login_redirects_to_settings(): void
    {
        $platformUser = PlatformUser::create([
            'name' => 'Admin',
            'email' => 'plat-guest-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($platformUser, 'platform')
            ->get('/platform/login');

        $response->assertRedirect(route('platform.settings.index', absolute: false));
    }

    public function test_platform_login_can_load_settings_page_after_redirect(): void
    {
        PlatformUser::create([
            'name' => 'Admin',
            'email' => 'plat-chain-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->followingRedirects()
            ->post('/platform/login', [
                'email' => PlatformUser::query()->latest('created_at')->value('email'),
                'password' => 'password',
            ]);

        $response->assertOk();
        $response->assertSee('Platform Settings');
        $this->assertAuthenticated('platform');
    }

    public function test_platform_login_with_inertia_uses_external_location(): void
    {
        PlatformUser::create([
            'name' => 'Admin',
            'email' => 'plat-inertia-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Inertia', 'true')
            ->post('/platform/login', [
                'email' => PlatformUser::query()->latest('created_at')->value('email'),
                'password' => 'password',
            ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('platform.settings.index'));
        $this->assertAuthenticated('platform');
    }

    public function test_unauthenticated_platform_settings_redirects_to_platform_login(): void
    {
        $response = $this->get('/platform/settings');

        $response->assertRedirect(route('platform.login', absolute: false));
    }

    public function test_stale_tenant_session_on_login_does_not_redirect_loop(): void
    {
        $user = User::factory()->create();
        \Modules\Tenancy\Models\Tenant::where('created_by', $user->id)->update(['created_by' => null]);
        \Modules\Tenancy\Models\TenantUser::where('user_id', $user->id)->delete();

        $response = $this->actingAs($user, 'web')
            ->followingRedirects()
            ->get('/login');

        $response->assertOk();
        $this->assertGuest('web');
    }

    public function test_tenant_storage_resolver_defaults_to_shared_db(): void
    {
        $tenant = Tenant::factory()->create(['isolation_level' => 'shared']);
        $resolver = app(\App\Support\Contracts\Tenancy\TenantStorageResolver::class);

        $this->assertSame('shared_db', $resolver->mode());
        $this->assertSame('tenant', $resolver->connectionFor($tenant));
        $this->assertTrue($resolver->usesExplicitTenantIdColumn($tenant));
    }
}
