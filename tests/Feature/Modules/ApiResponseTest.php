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
        $response = $this->getJson('/api/v1/apis');

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertStatus(401);
    }

    public function test_api_returns_standard_success_format_when_authenticated(): void
    {
        $user = User::factory()->create();
        $this->createPersonalTenant($user);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apis');

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
