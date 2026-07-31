<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Str;
use LogicException;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoPlatformControl;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoConfigGovernanceService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Services\SsoKillSwitchService;
use Modules\Identity\Services\SsoOperationalGate;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-082 WS8 — IdP governance, test-mode, kill switches (T41/T42 + D34).
 */
class HostEnterpriseSsoWs8GovernanceTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        SsoPlatformControl::current()->forceFill([
            'pause_new_initiations' => false,
            'disable_enterprise_sso' => false,
        ])->save();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{tenant: Tenant, user: User, versionId: string}
     */
    protected function provisionActiveSso(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws8-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS8 Admin',
            'email' => 'ws8-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret-value',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        return ['tenant' => $tenant->fresh(), 'user' => $user, 'versionId' => $versionId];
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function active_version_material_is_immutable(): void
    {
        $fixture = $this->provisionActiveSso();
        tenancy()->initialize($fixture['tenant']);
        $v1 = TenantSsoConfigVersion::query()->findOrFail($fixture['versionId']);
        $this->expectException(LogicException::class);
        $v1->update(['issuer_url' => 'https://evil.example']);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function material_update_creates_new_active_version_bound_by_transactions(): void
    {
        $fixture = $this->provisionActiveSso();
        tenancy()->initialize($fixture['tenant']);
        $svc = app(SsoConfigService::class);
        $svc->update($fixture['tenant'], ['client_id' => 'client-v2']);
        $v2 = $svc->getActiveVersionId($fixture['tenant']);
        $this->assertNotSame($fixture['versionId'], $v2);
        $old = TenantSsoConfigVersion::query()->findOrFail($fixture['versionId']);
        $this->assertSame(TenantSsoConfigVersion::STATUS_SUPERSEDED, $old->status);
        tenancy()->end();

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => 'acme.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => (string) $v2,
            'expected_issuer' => 'https://idp.example.com',
        ]);
        $this->assertSame((string) $v2, $created['transaction']->idp_configuration_version_id);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function disabled_or_superseded_version_cannot_start_new_login(): void
    {
        $fixture = $this->provisionActiveSso();
        $gate = app(SsoOperationalGate::class);
        app(SsoKillSwitchService::class)->disableVersion($fixture['tenant'], $fixture['versionId']);

        $this->expectException(SsoSecurityException::class);
        $gate->assertMayProceed($fixture['tenant'], SsoOperationalGate::STAGE_INITIATION);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function test_only_configuration_blocks_production_session_and_does_not_auto_activate(): void
    {
        $fixture = $this->provisionActiveSso();
        $gov = app(SsoConfigGovernanceService::class);

        tenancy()->initialize($fixture['tenant']);
        $draft = $gov->createDraftFromMaterial($fixture['tenant'], [
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'test-client',
        ]);
        $gov->validateVersion($fixture['tenant'], (string) $draft->id);
        $testOnly = $gov->markTestOnly($fixture['tenant'], (string) $draft->id);
        $this->assertSame(TenantSsoConfigVersion::STATUS_TEST_ONLY, $testOnly->status);

        try {
            $gov->activateVersion($fixture['tenant'], (string) $testOnly->id);
            $this->fail('Test-only must not activate');
        } catch (SsoSecurityException) {
            // expected
        }

        $gate = app(SsoOperationalGate::class);
        try {
            $gate->assertMayProceed(
                $fixture['tenant'],
                SsoOperationalGate::STAGE_SESSION_CREATE,
                (string) $testOnly->id,
                (string) $fixture['user']->id,
                allowTestOnly: true,
            );
            $this->fail('Test-only must not create production sessions');
        } catch (SsoSecurityException $e) {
            $this->assertStringContainsString('production sessions', $e->getMessage());
        }

        // Original production active version remains the production pointer until explicitly changed.
        $active = app(SsoConfigService::class)->getActiveVersion($fixture['tenant']);
        $this->assertNotNull($active);
        $this->assertSame(TenantSsoConfigVersion::STATUS_ACTIVE, $active->status);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function activation_is_atomic_and_requires_approval(): void
    {
        $fixture = $this->provisionActiveSso();
        $gov = app(SsoConfigGovernanceService::class);
        tenancy()->initialize($fixture['tenant']);
        $draft = $gov->createDraftFromMaterial($fixture['tenant'], [
            'client_id' => 'approved-client',
        ]);
        $gov->validateVersion($fixture['tenant'], (string) $draft->id);

        try {
            $gov->activateVersion($fixture['tenant'], (string) $draft->id);
            $this->fail('Unapproved must not activate');
        } catch (SsoSecurityException) {
            // expected
        }

        $gov->approveVersion($fixture['tenant'], (string) $draft->id);
        $active = $gov->activateVersion($fixture['tenant'], (string) $draft->id);
        $this->assertSame(TenantSsoConfigVersion::STATUS_ACTIVE, $active->status);
        $this->assertSame((string) $active->id, app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']));
        $this->assertSame('approved-client', TenantSsoConfig::query()->first()->client_id);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function unauthorized_actor_lacks_approve_and_activate_permissions(): void
    {
        $fixture = $this->provisionActiveSso();
        app(TenantRbacProvisioner::class)->ensureGlobalPermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(TenantRbacProvisioner::class)->ensureRolesForTenant($fixture['tenant']);

        tenancy()->initialize($fixture['tenant']);
        $member = User::create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Member',
            'email' => 'ws8-member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $member->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($fixture['tenant']->getTenantKey());
        $member->assignRole('member');
        $fixture['user']->assignRole('tenant-admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($member->can('tenant.sso.approve'));
        $this->assertFalse($member->can('tenant.sso.activate'));
        $this->assertFalse($member->can('tenant.sso.kill-switch'));
        $this->assertTrue($fixture['user']->can('tenant.sso.approve'));
        $this->assertTrue($fixture['user']->can('tenant.sso.activate'));
        $this->assertTrue($fixture['user']->can('tenant.sso.kill-switch'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function fresh_mfa_required_for_activate_controller_action(): void
    {
        $fixture = $this->provisionActiveSso();
        tenancy()->initialize($fixture['tenant']);
        $draft = app(SsoConfigGovernanceService::class)->createDraftFromMaterial($fixture['tenant'], [
            'client_id' => 'mfa-client',
        ]);
        app(SsoConfigGovernanceService::class)->validateVersion($fixture['tenant'], (string) $draft->id);
        app(SsoConfigGovernanceService::class)->approveVersion($fixture['tenant'], (string) $draft->id);

        $this->actingAs($fixture['user']);
        $request = \Illuminate\Http\Request::create('/security/sso/versions/'.$draft->id.'/activate', 'POST');
        $request->setUserResolver(fn () => $fixture['user']);
        $route = new \Illuminate\Routing\Route(['POST'], '/security/sso/versions/{versionId}/activate', []);
        $route->bind($request);
        $route->setParameter('versionId', (string) $draft->id);
        $request->setRouteResolver(static fn () => $route);
        $controller = app(\Modules\Identity\Http\Controllers\SsoGovernanceController::class);

        try {
            $controller->activateVersion($request);
            $this->fail('MFA step-up required');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        MfaVerificationContext::markVerified('sso.activate');
        tenancy()->initialize($fixture['tenant']);
        $response = $controller->activateVersion($request);
        $this->assertSame(200, $response->getStatusCode());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t41_platform_pause_and_tenant_kill_switch_block_initiation_without_fallback(): void
    {
        $fixture = $this->provisionActiveSso();
        $gate = app(SsoOperationalGate::class);
        $kills = app(SsoKillSwitchService::class);

        $kills->pausePlatformInitiations(true);
        try {
            $gate->assertMayProceed($fixture['tenant'], SsoOperationalGate::STAGE_INITIATION);
            $this->fail('paused platform must block');
        } catch (SsoSecurityException $e) {
            $this->assertStringContainsString('paused', $e->getMessage());
        }
        $kills->pausePlatformInitiations(false);

        $kills->pauseTenant($fixture['tenant']);
        try {
            $gate->assertMayProceed($fixture['tenant'], SsoOperationalGate::STAGE_INITIATION);
            $this->fail('paused tenant must block');
        } catch (SsoSecurityException $e) {
            $this->assertStringContainsString('paused', $e->getMessage());
        }

        $this->assertFalse(app(SsoConfigService::class)->isOperationalForTenant($fixture['tenant']));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function tenant_kill_switch_is_scoped_and_version_disable_is_scoped(): void
    {
        $a = $this->provisionActiveSso();
        $b = $this->provisionActiveSso();
        app(SsoKillSwitchService::class)->disableTenant($a['tenant']);

        $this->assertFalse(app(SsoConfigService::class)->isOperationalForTenant($a['tenant']));
        $this->assertTrue(app(SsoConfigService::class)->isOperationalForTenant($b['tenant']));

        app(SsoKillSwitchService::class)->disableVersion($b['tenant'], $b['versionId']);
        $this->assertFalse(app(SsoConfigService::class)->isOperationalForTenant($b['tenant']));
        $this->assertNull(app(SsoConfigService::class)->getActiveVersionId($b['tenant']));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function security_disable_cancels_inflight_and_blocks_session_creation(): void
    {
        $fixture = $this->provisionActiveSso();
        $tx = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => 'acme.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $fixture['versionId'],
            'expected_issuer' => 'https://idp.example.com',
        ]);

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'sess-ws8',
            'idp_configuration_version_id' => $fixture['versionId'],
            'idp_issuer' => 'https://idp.example.com',
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        app(SsoKillSwitchService::class)->securityDisableTenant($fixture['tenant'], 'compromise', true);

        $txn = SsoAuthenticationTransaction::query()->findOrFail($tx['transaction']->id);
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);

        tenancy()->initialize($fixture['tenant']);
        $this->assertNotNull(UserSession::query()->where('session_id', 'sess-ws8')->value('revoked_at'));
        tenancy()->end();

        $this->expectException(SsoSecurityException::class);
        app(SsoOperationalGate::class)->assertMayProceed(
            $fixture['tenant'],
            SsoOperationalGate::STAGE_SESSION_CREATE,
            $fixture['versionId'],
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function reenable_requires_recovery_and_rollback_rejects_revoked_secret(): void
    {
        $fixture = $this->provisionActiveSso();
        $gov = app(SsoConfigGovernanceService::class);
        $kills = app(SsoKillSwitchService::class);

        $kills->securityDisableTenant($fixture['tenant'], 'incident', false);

        try {
            $gov->setRolloutState($fixture['tenant'], TenantSsoConfig::ROLLOUT_ENABLED);
            $this->fail('direct re-enable must fail');
        } catch (SsoSecurityException) {
            // expected
        }

        tenancy()->initialize($fixture['tenant']);
        $draft = $gov->createDraftFromMaterial($fixture['tenant'], ['client_id' => 'recovery']);
        $gov->validateVersion($fixture['tenant'], (string) $draft->id);
        $gov->approveVersion($fixture['tenant'], (string) $draft->id);
        $kills->revokeVersionSecret($fixture['tenant'], (string) $draft->id);

        try {
            $gov->recoverFromSecurityDisable($fixture['tenant'], (string) $draft->id);
            $this->fail('revoked secret must not recover');
        } catch (SsoSecurityException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }

        $draft2 = $gov->createDraftFromMaterial($fixture['tenant'], ['client_id' => 'recovery-ok']);
        $gov->validateVersion($fixture['tenant'], (string) $draft2->id);
        $gov->approveVersion($fixture['tenant'], (string) $draft2->id);
        $recovered = $gov->recoverFromSecurityDisable($fixture['tenant'], (string) $draft2->id);
        $this->assertSame(TenantSsoConfigVersion::STATUS_ACTIVE, $recovered->status);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function race_security_disable_before_session_create_fails_closed(): void
    {
        $fixture = $this->provisionActiveSso();
        app(SsoKillSwitchService::class)->securityDisableTenant($fixture['tenant'], 'race', false);

        $this->expectException(SsoSecurityException::class);
        app(SsoOperationalGate::class)->assertMayProceed(
            $fixture['tenant'],
            SsoOperationalGate::STAGE_HANDOFF_ISSUE,
            $fixture['versionId'],
        );
    }
}
