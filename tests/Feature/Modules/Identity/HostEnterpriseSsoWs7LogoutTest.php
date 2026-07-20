<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoBackchannelLogoutEvent;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBackChannelLogoutTokenValidator;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoMfaContinuation;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-082 Workstream 7 — Tenant logout, BC Logout, cleanup. */
class HostEnterpriseSsoWs7LogoutTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected string $issuer = 'https://idp.example.com';

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{tenant: Tenant, user: User, host: string, versionId: string, link: TenantUserIdentity}
     */
    protected function prepareTenantWithSsoUser(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws7-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS7 User',
            'email' => 'ws7-'.uniqid().'@example.com',
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
            'issuer_url' => $this->issuer,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret-value-for-hs256',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'subject-ws7',
            'email_at_link' => $user->email,
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'host' => $tenant->slug.'.jabal.test',
            'versionId' => $versionId,
            'link' => $link,
        ];
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function tenant_host_logout_clears_session_and_sso_cookies(): void
    {
        $fixture = $this->prepareTenantWithSsoUser();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $this->actingAs($fixture['user'], 'web');
        $this->withSession([
            'tenant_id' => $fixture['tenant']->id,
            'mfa_verified_at' => now()->toIso8601String(),
            SsoMfaContinuation::SESSION_KEY => ['user_id' => $fixture['user']->id],
        ]);

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => $this->app['session']->getId(),
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        $response = $this->call(
            'POST',
            'https://'.$fixture['host'].'/logout',
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );
        $response->assertRedirect();
        $this->assertGuest('web');

        $cookies = $response->headers->getCookies();
        $names = array_map(static fn ($c) => $c->getName(), $cookies);
        $this->assertContains(SsoBrowserBindingCookieFactory::TENANT_CONTINUATION, $names);
        $this->assertContains(SsoBrowserBindingCookieFactory::AUTH_BINDING, $names);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function backchannel_logout_revokes_sessions_by_sid_and_is_idempotent(): void
    {
        $fixture = $this->prepareTenantWithSsoUser();
        $validator = app(SsoBackChannelLogoutTokenValidator::class);
        $secret = 'client-secret-value-for-hs256';
        $jti = 'jti-'.Str::uuid()->toString();

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'laravel-session-1',
            'idp_sid' => 'provider-sid-1',
            'idp_issuer' => $this->issuer,
            'identity_link_id' => $fixture['link']->id,
            'idp_configuration_version_id' => $fixture['versionId'],
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        $token = $validator->mintHmacSha256ForTests([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => $jti,
            'sid' => 'provider-sid-1',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], $secret);

        $url = 'https://auth.jabal.test/auth/enterprise-sso/backchannel-logout?tenant='.$fixture['tenant']->id;
        $this->call(
            'POST',
            $url,
            ['logout_token' => $token],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertOk();

        tenancy()->initialize($fixture['tenant']);
        $this->assertNotNull(UserSession::query()->where('idp_sid', 'provider-sid-1')->value('revoked_at'));
        tenancy()->end();

        $this->assertSame(1, SsoBackchannelLogoutEvent::query()->count());

        // Replay is idempotent (200, no error).
        $this->call(
            'POST',
            $url,
            ['logout_token' => $token],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertOk();
        $this->assertSame(1, SsoBackchannelLogoutEvent::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function backchannel_logout_rejects_invalid_signature_without_session_changes(): void
    {
        $fixture = $this->prepareTenantWithSsoUser();
        $validator = app(SsoBackChannelLogoutTokenValidator::class);
        $token = $validator->mintHmacSha256ForTests([
            'iss' => $this->issuer,
            'aud' => 'client-id',
            'iat' => time(),
            'jti' => 'jti-bad-'.uniqid(),
            'sid' => 'provider-sid-x',
            'events' => [SsoBackChannelLogoutTokenValidator::EVENT_TYPE => new \stdClass],
        ], 'wrong-secret');

        tenancy()->initialize($fixture['tenant']);
        UserSession::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $fixture['user']->id,
            'session_id' => 'laravel-session-2',
            'idp_sid' => 'provider-sid-x',
            'idp_issuer' => $this->issuer,
            'idp_configuration_version_id' => $fixture['versionId'],
            'logged_in_at' => now(),
            'last_activity_at' => now(),
        ]);
        tenancy()->end();

        $this->call(
            'POST',
            'https://auth.jabal.test/auth/enterprise-sso/backchannel-logout?tenant='.$fixture['tenant']->id,
            ['logout_token' => $token],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertStatus(400);

        tenancy()->initialize($fixture['tenant']);
        $this->assertNull(UserSession::query()->where('idp_sid', 'provider-sid-x')->value('revoked_at'));
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function cleanup_command_expires_stale_transactions_and_handoffs(): void
    {
        $fixture = $this->prepareTenantWithSsoUser();
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $fixture['versionId'],
            'expected_issuer' => $this->issuer,
        ]);
        $txn = $created['transaction'];
        $txn->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('identity:sso-cleanup-transient')->assertSuccessful();

        $fresh = $txn->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertNotNull($fresh->secrets_erased_at);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_ws7_surfaces_exist_and_path_has_no_backchannel_route(): void
    {
        $this->assertTrue(
            str_contains(
                file_get_contents(base_path('Modules/Identity/routes/web.php')),
                'backchannel-logout'
            )
        );

        $this->forceAddressingEnv('path');
        $this->refreshApplication();
        $this->post('/auth/enterprise-sso/backchannel-logout', ['logout_token' => 'x'])->assertNotFound();
    }
}
