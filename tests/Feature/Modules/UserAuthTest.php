<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PHASE 2: Login redirects to tenant-scoped dashboard /t/{tenant}/dashboard.
     */
    public function test_login_redirects_to_dashboard(): void
    {
        $email = 'user-auth-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Test User', $email);
        $tenant = $user->personalTenant();

        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/t/'.$tenant->id.'/dashboard');
        $this->assertAuthenticated();
    }

    public function test_logout_clears_session(): void
    {
        $user = $this->registerTenantUser('Logout User', 'logout-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $response = $this->actingAsTenantUser($user, $tenant, 'web')->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
