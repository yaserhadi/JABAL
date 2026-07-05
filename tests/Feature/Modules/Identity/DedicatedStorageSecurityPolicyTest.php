<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Services\SecurityPolicyService;
use Tests\Concerns\InteractsWithDedicatedSecurityRbac;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-053 / BK-043 — security policies on dedicated physical DB. */
class DedicatedStorageSecurityPolicyTest extends TestCase
{
    use InteractsWithDedicatedSecurityRbac;
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDedicatedTenantMode();
    }

    public function test_security_policy_service_creates_row_on_dedicated_db(): void
    {
        $email = 'ded-sp-svc-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        tenancy()->initialize($tenant);
        try {
            app(SecurityPolicyService::class)->getForTenant($tenant);

            $this->assertTableRowOnDedicatedNotShared('tenant_security_policies', [
                'tenant_id' => $tenant->id,
            ], $connection);
        } finally {
            tenancy()->end();
        }
    }

    public function test_security_policy_controller_get_on_dedicated_tenant(): void
    {
        $email = 'ded-sp-ctrl-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        tenancy()->initialize($tenant);
        try {
            $this->seedSecurityPolicyRbac();
            $this->assignSecurityPolicyAdmin($user, $tenant);
        } finally {
            tenancy()->end();
        }

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/t/'.$tenant->id.'/security/policies')
            ->assertOk()
            ->assertJsonStructure(['data' => ['mfa_required', 'mfa_grace_period_days', 'password_policy', 'session_idle_timeout']]);

        $this->assertTableRowOnDedicatedNotShared('tenant_security_policies', [
            'tenant_id' => $tenant->id,
        ], $connection);
    }
}
