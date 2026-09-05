<?php

namespace Tests\Feature\Modules\Api;

use Modules\Identity\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_health_endpoint_returns_success(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'ok',
                    'version' => 'v1',
                ],
            ])
            ->assertJsonStructure([
                'data',
                'meta' => [
                    'request_id',
                    'timestamp',
                ],
            ]);
    }

    public function test_api_requires_authentication_for_protected_routes(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    /**
     * PHASE 2: /api/v1/me requires X-Tenant-Id header and token with tenant ability.
     */
    public function test_authenticated_user_can_access_user_endpoint(): void
    {
        $user = TenantUser::factory()->create();
        $tenant = $this->createPersonalTenant($user);
        $this->assignDashboardViewToUser($user, $tenant);

        // Create token with tenant ability
        $token = $user->createToken('test-token', ['tenant:'.$tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $tenant->id,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'current_tenant' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'current_tenant',
                ],
            ]);
    }

    public function test_api_returns_consistent_error_format(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
    }
}
