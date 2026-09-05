<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantUser;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoPlatformControl;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Services\SsoConfigGovernanceService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Services\SsoKillSwitchService;
use Modules\Identity\Services\SsoOperationalExposureService;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-082 WS9 — Host SSO operational login exposure (no internal leakage).
 */
class HostEnterpriseSsoWs9ExposureTest extends TestCase
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
     * @return array{tenant: Tenant, user: User, host: string}
     */
    protected function provisionOperationalTenant(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws9-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        tenancy()->initialize($tenant);
        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS9 User',
            'email' => 'ws9-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
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
        tenancy()->end();

        return ['tenant' => $tenant->fresh(), 'user' => $user, 'host' => $host];
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_login_exposes_sso_only_when_operational(): void
    {
        $fixture = $this->provisionOperationalTenant();
        $exposure = app(SsoOperationalExposureService::class);
        $this->assertTrue($exposure->isExposedOnTenantLogin($fixture['tenant']));

        $response = $this->call(
            'GET',
            'https://'.$fixture['host'].'/login',
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );
        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Auth/TenantLogin')
                ->where('ssoOperational', true)
                ->where('ssoStartUrl', 'https://'.$fixture['host'].'/auth/enterprise-sso/start')
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_hides_sso_without_entitlement_or_active_version(): void
    {
        $fixture = $this->provisionOperationalTenant();
        $exposure = app(SsoOperationalExposureService::class);

        // Clear entitlement by disabling SSO flag (simulates non-operational).
        tenancy()->initialize($fixture['tenant']);
        app(SsoConfigService::class)->update($fixture['tenant'], ['enabled' => false]);
        tenancy()->end();
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture['tenant']));

        $fixture2 = $this->provisionOperationalTenant();
        app(SsoKillSwitchService::class)->disableVersion(
            $fixture2['tenant'],
            (string) app(SsoConfigService::class)->getActiveVersionId($fixture2['tenant'])
        );
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture2['tenant']));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_hides_sso_when_paused_security_disabled_or_platform_kill(): void
    {
        $fixture = $this->provisionOperationalTenant();
        $exposure = app(SsoOperationalExposureService::class);
        $kills = app(SsoKillSwitchService::class);

        $kills->pauseTenant($fixture['tenant']);
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture['tenant']));

        $fixture2 = $this->provisionOperationalTenant();
        $kills->securityDisableTenant($fixture2['tenant'], 'ws9', false);
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture2['tenant']));

        $fixture3 = $this->provisionOperationalTenant();
        $kills->disablePlatformEnterpriseSso(true);
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture3['tenant']));
        $kills->disablePlatformEnterpriseSso(false);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function pilot_and_test_only_hidden_from_ordinary_anonymous_users(): void
    {
        $fixture = $this->provisionOperationalTenant();
        $exposure = app(SsoOperationalExposureService::class);

        tenancy()->initialize($fixture['tenant']);
        TenantSsoConfig::query()->where('tenant_id', $fixture['tenant']->id)->update([
            'rollout_state' => TenantSsoConfig::ROLLOUT_PILOT,
            'pilot_user_id_hashes' => [SsoSecretCrypto::proof((string) $fixture['user']->id)],
        ]);
        tenancy()->end();

        // Anonymous login page: no actor → not exposed.
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture['tenant'], null));
        // Pilot actor: exposed.
        $this->assertTrue($exposure->isExposedOnTenantLogin($fixture['tenant'], (string) $fixture['user']->id));

        $fixture2 = $this->provisionOperationalTenant();
        tenancy()->initialize($fixture2['tenant']);
        $draft = app(SsoConfigGovernanceService::class)->createDraftFromMaterial($fixture2['tenant'], [
            'client_id' => 'test-only',
        ]);
        app(SsoConfigGovernanceService::class)->validateVersion($fixture2['tenant'], (string) $draft->id);
        app(SsoConfigGovernanceService::class)->markTestOnly($fixture2['tenant'], (string) $draft->id);
        tenancy()->end();
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture2['tenant'], null));
        $this->assertFalse($exposure->isExposedOnTenantLogin($fixture2['tenant'], (string) $fixture2['user']->id));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function start_failure_returns_generic_error_without_password_auto_fallback(): void
    {
        $fixture = $this->provisionOperationalTenant();
        app(SsoKillSwitchService::class)->pauseTenant($fixture['tenant']);

        $response = $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/start',
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );
        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'not available',
            strtolower((string) session('errors')->first('email'))
        );
    }
}
