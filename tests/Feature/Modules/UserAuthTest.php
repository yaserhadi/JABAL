<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * BK-064: Tenant-local login redirects to tenant-scoped dashboard.
     */
    public function test_login_redirects_to_dashboard(): void
    {
        $email = 'user-auth-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Test User', $email);
        $tenant = $user->homeTenant();

        $response = $this->post('/t/'.$tenant->entryKey().'/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/t/'.$tenant->entryKey().'/dashboard');
        $this->assertAuthenticated();
    }

    public function test_logout_clears_session(): void
    {
        $user = $this->registerTenantUser('Logout User', 'logout-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();

        $response = $this->actingAsTenantUser($user, $tenant, 'web')
            ->withSession(['tenant_id' => $tenant->id])
            ->post(route('logout'));

        $response->assertRedirect(route('tenant.login', ['tenant' => $tenant->slug]));
        $this->assertGuest();
    }
}
