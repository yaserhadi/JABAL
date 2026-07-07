<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Services\SsoConfigService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-008 minimal tenant login entry surface. */
class SsoTenantLoginTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function tenant_login_renders_for_active_organization(): void
    {
        $tenant = Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'active',
        ]);

        $this->get('/t/'.$tenant->id.'/login')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Auth/TenantLogin')
                    ->where('tenant.id', $tenant->id)
                    ->where('ssoOperational', false)
            );
    }

    #[Test]
    public function tenant_login_shows_sso_when_operational(): void
    {
        $tenant = Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $this->get('/t/'.$tenant->id.'/login')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Auth/TenantLogin')
                    ->where('ssoOperational', true)
            );
    }

    #[Test]
    public function tenant_login_does_not_expose_secrets(): void
    {
        $tenant = Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://example.com',
            'client_id' => 'client-id',
            'client_secret' => 'top-secret-value',
        ]);
        tenancy()->end();

        $body = $this->get('/t/'.$tenant->id.'/login')->assertOk()->getContent() ?? '';
        $this->assertStringNotContainsString('top-secret-value', $body);
        $this->assertStringNotContainsString('client_secret', $body);
    }

    #[Test]
    public function personal_tenant_login_is_not_found(): void
    {
        $user = $this->registerTenantUser('Personal', 'personal-login-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $this->get('/t/'.$tenant->id.'/login')->assertNotFound();
    }

    #[Test]
    public function inactive_organization_tenant_login_is_not_found(): void
    {
        $tenant = Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'suspended',
        ]);

        $this->get('/t/'.$tenant->id.'/login')->assertNotFound();
    }
}
