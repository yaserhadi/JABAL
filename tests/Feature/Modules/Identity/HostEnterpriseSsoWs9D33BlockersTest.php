<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Domain;
use Modules\Identity\Models\TenantUser;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SessionRegistryService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoMfaContinuation;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Identity\Support\Sso\SsoTrustedJwksResolver;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-082 WS9 — D33 blocker-row fixtures (T03/T06–T08/T15/T19–T23/T27–T28/T53).
 */
class HostEnterpriseSsoWs9D33BlockersTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   user: User,
     *   link: TenantUserIdentity,
     *   host: string,
     *   versionId: string
     * }
     */
    protected function provisionTenantWithLink(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws9b-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        tenancy()->initialize($tenant);
        $user = TenantUser::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS9B User',
            'email' => 'ws9b-'.uniqid().'@example.com',
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
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://idp.example.com',
            'subject' => 'subject-ws9b',
            'email_at_link' => $user->email,
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'link' => $link,
            'host' => $host,
            'versionId' => $versionId,
        ];
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   user: User,
     *   link: TenantUserIdentity,
     *   created: array<string, mixed>,
     *   authBinding: string,
     *   host: string
     * }
     */
    protected function prepareAwaitingCallback(): array
    {
        $base = $this->provisionTenantWithLink();
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $base['tenant']->id,
            'destination_host' => $base['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $base['versionId'],
            'expected_issuer' => 'https://idp.example.com',
        ]);
        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);

        return array_merge($base, [
            'created' => $created,
            'authBinding' => $authBinding,
        ]);
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   user: User,
     *   link: TenantUserIdentity,
     *   handoffReference: string,
     *   continuation: string,
     *   host: string,
     *   purpose: string
     * }
     */
    protected function prepareIssuedHandoff(string $purpose = SsoAuthenticationTransaction::PURPOSE_ORDINARY): array
    {
        $base = $this->provisionTenantWithLink();
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $base['tenant']->id,
            'destination_host' => $base['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $base['versionId'],
            'expected_issuer' => 'https://idp.example.com',
            'purpose' => $purpose,
        ]);
        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $txn = app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);
        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($txn->id);
        $this->assertNotNull($reserved);
        $issued = app(AuthenticationTransactionService::class)->issueHandoff($reserved, [
            'user_id' => (string) $base['user']->id,
            'identity_link_id' => (string) $base['link']->id,
        ]);

        return [
            'tenant' => $base['tenant'],
            'user' => $base['user'],
            'link' => $base['link'],
            'handoffReference' => $issued['reference'],
            'continuation' => $created['tenant_continuation_secret'],
            'host' => $base['host'],
            'purpose' => $purpose,
        ];
    }

    protected function handoffCall(array $fixture)
    {
        return $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($fixture['handoffReference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $fixture['continuation']],
            server: [
                'HTTP_HOST' => $fixture['host'],
                'SERVER_NAME' => $fixture['host'],
                'HTTPS' => 'on',
            ]
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t03_domain_reassigned_mid_flight_handoff_fails_closed(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $other = Tenant::factory()->create([
            'slug' => 'ws9b-other-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);

        // Platform subdomain rows store the DNS label (slug), not FQDN.
        $domain = Domain::query()->where('domain', $fixture['tenant']->slug)->first();
        $this->assertNotNull($domain);
        $domain->forceFill(['tenant_id' => $other->id])->save();

        $this->handoffCall($fixture)->assertNotFound();
        $this->assertGuest('web');
        $this->assertSame(
            SsoTenantHandoff::STATUS_ISSUED,
            SsoTenantHandoff::query()->find(explode('.', $fixture['handoffReference'], 2)[0])->status
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t06_nonce_mismatch_fails_terminal_without_handoff(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')
            ->once()
            ->andThrow(new \RuntimeException('Nonce mismatch'));
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $txn = $fixture['created']['transaction']->fresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);
        $this->assertSame('token_exchange_failed', $txn->failure_reason);
        $this->assertSame(0, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t07_pkce_failure_fails_terminal_without_handoff(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')
            ->once()
            ->andThrow(new \RuntimeException('invalid_grant: PKCE verification failed'));
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $txn = $fixture['created']['transaction']->fresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);
        $this->assertSame('token_exchange_failed', $txn->failure_reason);
        $this->assertSame(0, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t08_duplicate_query_state_returns_404_without_exchange(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldNotReceive('exchangeHostAuthorizationCode');
        $this->app->instance(SsoAuthService::class, $mock);

        $state = rawurlencode($fixture['created']['state']);
        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback',
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
                'HTTPS' => 'on',
                'QUERY_STRING' => 'code=x&state='.$state.'&state=other',
            ]
        )->assertNotFound();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertSame(
            SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            $fixture['created']['transaction']->fresh()->status
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t15_consume_cas_single_winner_under_lock(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $svc = app(AuthenticationTransactionService::class);
        $tenantId = (string) $fixture['tenant']->id;

        $first = $svc->consumeHandoff(
            $fixture['handoffReference'],
            $tenantId,
            $fixture['host'],
            $fixture['continuation'],
        );
        $second = $svc->consumeHandoff(
            $fixture['handoffReference'],
            $tenantId,
            $fixture['host'],
            $fixture['continuation'],
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(
            SsoTenantHandoff::STATUS_CONSUMED,
            SsoTenantHandoff::query()->find(explode('.', $fixture['handoffReference'], 2)[0])->status
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t19_usersession_register_failure_fails_closed(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $this->mock(SessionRegistryService::class, function ($mock) {
            $mock->shouldReceive('register')->once()->andThrow(new \RuntimeException('registry_unavailable'));
        });

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $this->assertGuest('web');

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t20_authoritative_store_outage_fails_closed_without_session(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $this->partialMock(AuthenticationTransactionService::class, function ($mock) {
            $mock->shouldReceive('consumeHandoff')->once()->andThrow(new \RuntimeException('central_store_outage'));
        });

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('central_store_outage');
        try {
            $this->handoffCall($fixture);
        } finally {
            $this->assertGuest('web');
            $this->assertSame(
                SsoTenantHandoff::STATUS_ISSUED,
                SsoTenantHandoff::query()->find(explode('.', $fixture['handoffReference'], 2)[0])->status
            );
        }
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t21_jwks_cache_survives_primary_jwks_outage(): void
    {
        $uri = 'https://idp.example.com/jwks';
        $keys = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => 'x', 'e' => 'AQAB']]];
        Cache::put('sso.jwks.'.hash('sha256', $uri), $keys, 300);
        Http::fake([$uri => Http::response('unavailable', 500)]);

        $resolved = app(SsoTrustedJwksResolver::class)->fetchKeys($uri, forceRefresh: false);
        $this->assertSame($keys['keys'], $resolved['keys']);
        $this->assertFalse($resolved['refreshed']);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t22_mandatory_audit_failure_revokes_session(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $logger = Mockery::mock(AuditLoggerInterface::class);
        $logger->shouldReceive('log')->andThrow(new \RuntimeException('audit_unavailable'));
        $this->app->instance(AuditLoggerInterface::class, $logger);
        $this->app->instance(
            \Modules\Identity\Support\Sso\SsoSecurityAudit::class,
            new \Modules\Identity\Support\Sso\SsoSecurityAudit($logger)
        );
        $this->app->forgetInstance(\Modules\Identity\Services\HostEnterpriseSsoHandoffService::class);

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        // Fail-closed: no authenticated browser session after mandatory audit failure.
        $this->assertGuest('web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t23_handoff_regenerates_session_id(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $this->withSession(['probe' => 'pre']);
        $before = session()->getId();
        $this->assertNotSame('', $before);

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($fixture['user'], 'web');
        $after = session()->getId();
        $this->assertNotSame($before, $after);

        tenancy()->initialize($fixture['tenant']);
        $row = UserSession::query()->where('user_id', $fixture['user']->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($after, $row->session_id);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t27_reauthentication_purpose_replaces_same_user_session(): void
    {
        $fixture = $this->prepareIssuedHandoff(SsoAuthenticationTransaction::PURPOSE_REAUTHENTICATION);
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $this->mock(SessionRegistryService::class, function ($mock) {
            $mock->shouldReceive('register')->once();
        });

        $this->actingAs($fixture['user'], 'web');
        $this->withSession(['tenant_id' => $fixture['tenant']->id]);

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($fixture['user'], 'web');
        $this->assertSame(
            SsoTenantHandoff::STATUS_CONSUMED,
            SsoTenantHandoff::query()->find(explode('.', $fixture['handoffReference'], 2)[0])->status
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t28_preauth_defer_flag_does_not_imply_usersession_row(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->withSession([]);
        $store = session()->driver();
        SsoMfaContinuation::store($store, [
            'user_id' => (string) $fixture['user']->id,
            'tenant_id' => (string) $fixture['tenant']->id,
            'post_login_path' => '/dashboard',
            'handoff_id' => explode('.', $fixture['handoffReference'], 2)[0],
        ]);
        $store->put(SsoMfaContinuation::DEFER_USER_SESSION_KEY, true);

        $this->assertTrue((bool) $store->get(SsoMfaContinuation::DEFER_USER_SESSION_KEY));
        $this->assertNotNull($store->get(SsoMfaContinuation::SESSION_KEY));

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();

        // Cross-user handoff while authenticated remains unconsumed (full-session steal blocked).
        $this->actingAs($fixture['user'], 'web');
        $this->withSession(['tenant_id' => $fixture['tenant']->id]);

        tenancy()->initialize($fixture['tenant']);
        $other = TenantUser::create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Other',
            'email' => 'ws9b-other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $other->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $otherLink = TenantUserIdentity::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $other->id,
            'issuer' => 'https://idp.example.com',
            'subject' => 'subject-ws9b-other',
            'email_at_link' => $other->email,
        ]);
        $versionId = (string) app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']);
        tenancy()->end();

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
        ]);
        $txn = app(AuthenticationTransactionService::class)->attachAuthBinding(
            $created['transaction'],
            SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES)
        );
        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($txn->id);
        $issued = app(AuthenticationTransactionService::class)->issueHandoff($reserved, [
            'user_id' => (string) $other->id,
            'identity_link_id' => (string) $otherLink->id,
        ]);

        $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($issued['reference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $created['tenant_continuation_secret']],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(
            SsoTenantHandoff::STATUS_ISSUED,
            SsoTenantHandoff::query()->find(explode('.', $issued['reference'], 2)[0])->status
        );
        $this->assertAuthenticatedAs($fixture['user'], 'web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t53_entitlement_loss_after_handoff_blocks_session(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        tenancy()->initialize($fixture['tenant']);
        TenantSsoConfig::query()->where('tenant_id', $fixture['tenant']->id)->update([
            'enabled' => false,
            'disabled_by_entitlement' => true,
        ]);
        tenancy()->end();

        $this->handoffCall($fixture)->assertNotFound();
        $this->assertGuest('web');

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function t53_entitlement_loss_before_handoff_issue_fails_callback(): void
    {
        $fixture = $this->prepareAwaitingCallback();

        tenancy()->initialize($fixture['tenant']);
        TenantSsoConfig::query()->where('tenant_id', $fixture['tenant']->id)->update([
            'enabled' => false,
            'disabled_by_entitlement' => true,
        ]);
        tenancy()->end();

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws9b',
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $txn = $fixture['created']['transaction']->fresh();
        $this->assertNotSame(SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED, $txn->status);
    }
}
