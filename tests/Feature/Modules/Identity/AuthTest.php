<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt($password = 'password'),
        ]);
        $tenant = $this->createPersonalTenant($user);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/t/'.$tenant->id.'/dashboard');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

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
}
