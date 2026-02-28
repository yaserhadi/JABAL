<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Services\UserService;
use Tests\TestCase;

class TokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_api_token_with_credentials(): void
    {
        $userService = app(UserService::class);
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $tenant = $userService->createPersonalTenant($user);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                    ],
                    'tenant_id',
                ],
                'meta' => [
                    'request_id',
                    'timestamp',
                ],
            ])
            ->assertJson([
                'data' => [
                    'token_type' => 'Bearer',
                    'user' => [
                        'email' => 'test@example.com',
                    ],
                    'tenant_id' => $tenant->id,
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_user_can_specify_tenant_when_generating_token(): void
    {
        $userService = app(UserService::class);
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $personalTenant = $userService->createPersonalTenant($user);

        $orgTenant = \Modules\Tenancy\Models\Tenant::factory()->create([
            'type' => 'organization',
        ]);
        $userService->addUserToTenant($user, $orgTenant, 'member');

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'tenant_id' => $orgTenant->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'tenant_id' => $orgTenant->id,
                ],
            ]);
    }

    public function test_token_generation_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_token_generation_fails_for_non_existent_user(): void
    {
        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_cannot_generate_token_for_tenant_they_dont_belong_to(): void
    {
        $userService = app(UserService::class);
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $userService->createPersonalTenant($user);

        $otherTenant = \Modules\Tenancy\Models\Tenant::factory()->create();

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'TENANT_ACCESS_DENIED',
                ],
            ]);
    }

    public function test_user_can_revoke_their_token(): void
    {
        $userService = app(UserService::class);
        $user = User::factory()->create();
        $userService->createPersonalTenant($user);

        $accessToken = $user->createToken('test-token');
        $token = $accessToken->plainTextToken;
        $tokenId = $accessToken->accessToken->id;

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/token');

        $response->assertStatus(200);

        // Token row should be deleted from database
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
