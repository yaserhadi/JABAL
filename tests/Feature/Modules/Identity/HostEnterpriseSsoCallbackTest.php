<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Support\Str;
use Mockery;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoAuthorizationResponseParser;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-082 Workstream 4 — Auth Host callback + token exchange + Handoff mint. */
class HostEnterpriseSsoCallbackTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        config(['identity.sso.host_response_mode' => SsoAuthorizationResponseParser::MODE_QUERY]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{tenant: Tenant, user: User, link: TenantUserIdentity, created: array<string, mixed>, authBinding: string}
     */
    protected function prepareAwaitingCallback(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws4-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS4 User',
            'email' => 'ws4-'.uniqid().'@example.com',
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
            'subject' => 'subject-ws4',
            'email_at_link' => $user->email,
        ]);
        tenancy()->end();

        $versionId = app(SsoConfigService::class)->getActiveVersionId($tenant);
        $this->assertNotNull($versionId);

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $tenant->slug.'.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'expected_issuer' => 'https://idp.example.com',
        ]);

        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'link' => $link,
            'created' => $created,
            'authBinding' => $authBinding,
        ];
    }

    protected function mockSuccessfulTokenExchange(): void
    {
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws4',
            'email' => 'ws4@example.com',
            'email_verified' => true,
            'acr' => 'urn:example:aal1',
            'auth_time' => 1700000000,
        ]);

        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function successful_callback_exchanges_once_mints_handoff_and_redirects_to_tenant_host(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $this->mockSuccessfulTokenExchange();

        $response = $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=one-time-code&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        );

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://'.$fixture['tenant']->slug.'.jabal.test/auth/enterprise-sso/handoff?h=', $location);

        $txn = $fixture['created']['transaction']->fresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_HANDOFF_ISSUED, $txn->status);
        $this->assertNotNull($txn->secrets_erased_at);
        $this->assertSame(1, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function unsolicited_or_invalid_state_does_not_exchange_tokens(): void
    {
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldNotReceive('exchangeHostAuthorizationCode');
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state=missing.ref',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function auth_binding_mismatch_rejects_without_token_exchange(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldNotReceive('exchangeHostAuthorizationCode');
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=x&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => 'wrong-binding'],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(
            SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK,
            $fixture['created']['transaction']->fresh()->status
        );
        $this->assertSame(0, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function duplicate_callback_has_single_winner_and_single_exchange(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws4',
        ]);

        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $url = 'https://auth.jabal.test/auth/enterprise-sso/callback?code=dup&state='.rawurlencode($fixture['created']['state']);
        $cookies = [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']];
        $server = ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on'];

        $this->call('GET', $url, cookies: $cookies, server: $server)->assertRedirect();
        $this->call('GET', $url, cookies: $cookies, server: $server)->assertNotFound();

        $this->assertSame(1, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function ambiguous_timeout_is_terminal_without_handoff(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')
            ->once()
            ->andThrow(new \RuntimeException('cURL error 28: Operation timed out'));
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=slow&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $txn = $fixture['created']['transaction']->fresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);
        $this->assertSame('token_exchange_ambiguous', $txn->failure_reason);
        $this->assertSame(0, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function issuer_mixup_rejects_without_handoff(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://evil-idp.example.com',
            'sub' => 'subject-ws4',
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=mix&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertRedirect();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertSame(
            SsoAuthenticationTransaction::STATUS_FAILED,
            $fixture['created']['transaction']->fresh()->status
        );
        $this->assertGuest('web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function missing_identity_link_does_not_call_attempt_first_link_or_login(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        tenancy()->initialize($fixture['tenant']);
        $fixture['link']->delete();
        tenancy()->end();

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws4',
            'email' => $fixture['user']->email,
            'email_verified' => true,
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=nolink&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertRedirect();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');
        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, TenantUserIdentity::query()->count());
        tenancy()->end();

        $this->assertSame(
            SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED,
            $fixture['created']['transaction']->fresh()->failure_reason
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function form_post_rejects_multipart_content_type(): void
    {
        config(['identity.sso.host_response_mode' => SsoAuthorizationResponseParser::MODE_FORM_POST]);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->call(
            'POST',
            'https://auth.jabal.test/auth/enterprise-sso/callback',
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
                'HTTPS' => 'on',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
                'HTTP_CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            ],
            content: "--x\r\nContent-Disposition: form-data; name=\"state\"\r\n\r\ns\r\n--x--"
        )->assertNotFound();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function form_post_happy_path_exchanges_and_mints_handoff(): void
    {
        config(['identity.sso.host_response_mode' => SsoAuthorizationResponseParser::MODE_FORM_POST]);
        $fixture = $this->prepareAwaitingCallback();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws4',
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $body = http_build_query([
            'state' => $fixture['created']['state'],
            'code' => 'form-code',
        ]);

        $this->call(
            'POST',
            'https://auth.jabal.test/auth/enterprise-sso/callback',
            [],
            [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            [],
            [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
                'HTTPS' => 'on',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            $body
        )->assertRedirect();

        $this->assertSame(1, SsoTenantHandoff::query()->count());
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function legacy_host_callback_remains_404_and_ws4_has_no_auth_login(): void
    {
        $mock = Mockery::mock(SsoAuthService::class);
        $mock->shouldNotReceive('completeCallback');
        $mock->shouldNotReceive('exchangeHostAuthorizationCode');
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/sso/callback?code=x&state=y',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoCallbackController.php'));
        $service = file_get_contents(base_path('Modules/Identity/app/Services/HostEnterpriseSsoCallbackService.php'));
        foreach ([$controller, $service] as $source) {
            $this->assertStringNotContainsString('Auth::login(', $source);
            $this->assertStringNotContainsString('Auth::guard(', $source);
            $this->assertStringNotContainsString('attemptFirstLink(', $source);
            $this->assertStringNotContainsString('UserSession', $source);
            $this->assertStringNotContainsString('LaravelSessionAuthSessionAdapter', $source);
            $this->assertStringNotContainsString('consumeHandoff(', $source);
        }
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function authorization_code_not_persisted_on_transaction(): void
    {
        $fixture = $this->prepareAwaitingCallback();
        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://idp.example.com',
            'sub' => 'subject-ws4',
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=must-not-persist&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertRedirect();

        $attrs = $fixture['created']['transaction']->fresh()->getAttributes();
        $this->assertArrayNotHasKey('authorization_code', $attrs);
        $serialized = json_encode($attrs);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('must-not-persist', $serialized);
    }
}
