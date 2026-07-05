<?php

namespace Tests\Feature\Modules\Identity;

use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-053 / BK-021 — API tokens on dedicated physical DB. */
class DedicatedStorageApiTokenTest extends TestCase
{
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDedicatedTenantMode();
    }

    public function test_token_issue_persists_on_dedicated_db_not_shared(): void
    {
        $email = 'ded-token-issue-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertSame($tenant->id, $response->json('data.tenant_id'));

        $this->assertTableRowOnDedicatedNotShared('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'api-token',
        ], $connection);
    }

    public function test_token_list_returns_dedicated_tenant_tokens(): void
    {
        $email = 'ded-token-list-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        $issue = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'name' => 'list-token',
        ]);

        $issue->assertOk();
        $bearer = $issue->json('data.token');

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/v1/auth/tokens');

        $response->assertOk();
        $names = collect($response->json('data.tokens'))->pluck('name')->all();
        $this->assertContains('list-token', $names);

        $this->assertTableRowOnDedicatedNotShared('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'list-token',
        ], $connection);
    }
}
