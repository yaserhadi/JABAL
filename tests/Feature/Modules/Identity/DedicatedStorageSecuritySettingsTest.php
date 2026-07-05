<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\UserSession;
use Tests\Concerns\InteractsWithDedicatedSecurityRbac;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-053 / BK-035 — security settings UI on dedicated physical DB. */
class DedicatedStorageSecuritySettingsTest extends TestCase
{
    use InteractsWithDedicatedSecurityRbac;
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDedicatedTenantMode();
    }

    public function test_security_settings_page_loads_for_dedicated_tenant(): void
    {
        $email = 'ded-settings-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        tenancy()->initialize($tenant);
        try {
            $this->seedSecurityPolicyRbac();
            $this->assignSecurityPolicyAdmin($user, $tenant);
        } finally {
            tenancy()->end();
        }

        $response = $this->actingAsTenantUser($user, $tenant)
            ->get('/t/'.$tenant->id.'/security/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SecuritySettings/Index')
            ->has('sessions')
            ->has('mfa')
            ->has('tokens')
            ->has('policies'));

        $this->assertTableRowOnDedicatedNotShared('tenant_security_policies', [
            'tenant_id' => $tenant->id,
        ], $connection);
    }

    public function test_security_settings_lists_session_from_dedicated_db(): void
    {
        $email = 'ded-settings-sess-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);
        $sessionId = 'ded-ui-sess-'.uniqid();

        tenancy()->initialize($tenant);
        try {
            $this->seedSecurityPolicyRbac();
            $this->assignSecurityPolicyAdmin($user, $tenant);

            UserSession::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'device_label' => 'Dedicated Chrome',
                'last_activity_at' => now(),
                'logged_in_at' => now(),
            ]);
        } finally {
            tenancy()->end();
        }

        $this->assertTableRowOnDedicatedNotShared('user_sessions', [
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ], $connection);

        $response = $this->actingAsTenantUser($user, $tenant)
            ->get('/t/'.$tenant->id.'/security/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.device_label', 'Dedicated Chrome'));
    }
}
