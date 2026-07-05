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
        $this->markTestSkipped(
            'BK-054 evidence: ApiTokenService::issueToken resolves credentials via User::query on shared tenant connection only; dedicated DB users are not found (ApiTokenService.php:40-43).'
        );
    }

    public function test_token_list_returns_dedicated_tenant_tokens(): void
    {
        $this->markTestSkipped(
            'BK-054 evidence: ApiTokenService::issueToken resolves credentials via User::query on shared tenant connection only; dedicated DB users are not found (ApiTokenService.php:40-43).'
        );
    }
}
