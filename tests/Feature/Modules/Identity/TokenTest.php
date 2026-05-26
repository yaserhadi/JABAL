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
        $email = 'token-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Token User', $email);
        $password = 'password';

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => $password,
        ]);

        $tenant = $user->personalTenant();

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
                        'email' => $email,
                    ],
                    'tenant_id' => $tenant->id,
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_user_can_specify_tenant_when_generating_token(): void
    {
        $userService = app(UserService::class);
        $email = 'multi-'.uniqid().'@example.com';
        $user = $this->registerTenantUser('Multi Tenant', $email);

        $orgTenant = \Modules\Tenancy\Models\Tenant::factory()->create([
            'type' => 'organization',
            'status' => 'active',
        ]);
        $userService->addUserToTenant($user, $orgTenant, 'member');

        $this->assertDatabaseHas('tenant_users', [
            'user_id' => $user->id,
            'tenant_id' => $orgTenant->id,
            'status' => 'active',
        ], 'central');

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => 'password',
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
        $this->registerTenantUser('Bad Creds', 'bad@example.com');

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'bad@example.com',
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
        $this->registerTenantUser('Isolated', 'iso@example.com');

        $otherTenant = \Modules\Tenancy\Models\Tenant::factory()->create();

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'iso@example.com',
            'password' => 'password',
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
        $user = $this->registerTenantUser('Revoke User', 'revoke-'.uniqid().'@example.com');

        $accessToken = $user->createToken('test-token', ['tenant:'.$user->tenant_id]);
        $token = $accessToken->plainTextToken;
        $tokenId = $accessToken->accessToken->id;

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId], 'tenant');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-Id', $user->tenant_id)
            ->deleteJson('/api/v1/auth/token');

        $response->assertStatus(200);

        // Token row should be deleted from database
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId], 'tenant');
    }
}
