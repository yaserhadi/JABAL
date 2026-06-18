<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F8 — No cross-database auth dependency (ADR-0007, Wave 1 foundation report).
 */
class CrossDatabaseAuthDependencyTest extends TestCase
{
    public function test_platform_login_does_not_require_tenant_membership(): void
    {
        $email = 'plat-f8-'.uniqid().'@test.com';
        $user = PlatformUser::create([
            'name' => 'Platform Only',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($user);

        $response = $this->post('/platform/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('platform.settings.index', absolute: false));
        $this->assertAuthenticated('platform');
    }

    public function test_tenant_registration_does_not_create_platform_user(): void
    {
        $email = 'tenant-f8-'.uniqid().'@example.com';
        $this->registerTenantUser('F8 User', $email);

        $this->assertDatabaseMissing('platform_users', ['email' => $email], 'central');
    }
}
