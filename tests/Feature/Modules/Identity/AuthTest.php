<?php

namespace Tests\Feature\Modules\Identity;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_users_can_view_login_page(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * PHASE 2: Login redirects to tenant-scoped dashboard /t/{tenant}/dashboard.
     */
    public function test_users_can_authenticate_with_valid_credentials(): void
    {
        $email = 'auth-valid-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Test User', $email);
        $password = 'password';
        $tenant = $user->personalTenant();

        $response = $this->post('/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertAuthenticated('web');
        $response->assertRedirect('/t/'.$tenant->id.'/dashboard');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $this->registerTenantUser('Test User', 'test-wrong@example.com');

        $response = $this->post('/login', [
            'email' => 'test-wrong@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = $this->registerTenantUser('Logout User', 'logout-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $response = $this->actingAsTenantUser($user, $tenant, 'web')->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_register_redirects_to_dashboard_with_tenant_admin_permissions(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Signup',
            'email' => 'new-signup-'.uniqid().'@example.com',
            'password' => 'password-Str0ng!',
            'password_confirmation' => 'password-Str0ng!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#/t/[a-f0-9-]{36}/dashboard#', (string) $target);

        $dash = $this->get((string) $target);
        $dash->assertStatus(200);
    }

    /**
     * Mirrors local .env (database cache + tenancy bootstrapper): permission cache must use central store.
     */
    public function test_register_dashboard_with_database_cache_store(): void
    {
        config(['cache.default' => 'database']);
        config(['permission.cache.store' => 'central']);

        $response = $this->post('/register', [
            'name' => 'Cache Signup',
            'email' => 'cache-signup-'.uniqid().'@example.com',
            'password' => 'password-Str0ng!',
            'password_confirmation' => 'password-Str0ng!',
        ]);

        $this->assertAuthenticated();
        $target = $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#/t/[a-f0-9-]{36}/dashboard#', (string) $target);

        $this->get((string) $target)->assertOk();
    }
}
