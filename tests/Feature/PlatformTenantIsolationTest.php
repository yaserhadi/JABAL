<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
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

        $response->assertRedirect(route('login'));
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

    public function test_tenant_storage_resolver_defaults_to_shared_db(): void
    {
        $tenant = Tenant::factory()->create(['isolation_level' => 'shared']);
        $resolver = app(\App\Support\Contracts\Tenancy\TenantStorageResolver::class);

        $this->assertSame('shared_db', $resolver->mode());
        $this->assertSame('tenant', $resolver->connectionFor($tenant));
        $this->assertTrue($resolver->usesExplicitTenantIdColumn($tenant));
    }
}
