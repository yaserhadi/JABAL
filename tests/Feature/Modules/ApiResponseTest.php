<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_json_response(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertStatus(401);
    }

    /**
     * PHASE 2: /api/v1/me requires X-Tenant-Id header and token with tenant ability.
     */
    public function test_api_returns_standard_success_format_when_authenticated(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createPersonalTenant($user);
        $token = $user->createToken('test', ['tenant:' . $tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-Id' => $tenant->id,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'request_id',
                'timestamp',
            ],
        ]);
    }
}
