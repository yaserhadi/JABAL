<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-082 Workstream 3 — two-host initiation and browser binding (no callback). */
class HostEnterpriseSsoInitiationTest extends TestCase
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
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    protected function createHostTenantWithSso(): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws3-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        tenancy()->end();

        return $tenant->fresh();
    }

    /**
     * @return list<string>
     */
    protected function setCookieHeaders($response): array
    {
        return $response->headers->all('set-cookie');
    }

    protected function cookieHeaderNamed(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $name.'=')) {
                return $header;
            }
        }

        return null;
    }

    protected function assertHostOnlyBindingCookie(string $header): void
    {
        $this->assertDoesNotMatchRegularExpression('/(^|;\s*)Domain=/i', $header);
        $this->assertMatchesRegularExpression('/;\s*Path=\//i', $header);
        $this->assertMatchesRegularExpression('/;\s*HttpOnly/i', $header);
        $this->assertMatchesRegularExpression('/;\s*Secure/i', $header);
        $this->assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $header);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function tenant_host_start_sets_continuation_cookie_and_redirects_to_auth_initiate(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $response = $this->call(
            'GET',
            'https://'.$host.'/auth/enterprise-sso/start',
            server: [
                'HTTP_HOST' => $host,
                'SERVER_NAME' => $host,
                'HTTPS' => 'on',
            ]
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('https://auth.jabal.test/auth/enterprise-sso/initiate?t=', $location);

        $headers = $this->setCookieHeaders($response);
        $continuation = $this->cookieHeaderNamed($headers, SsoBrowserBindingCookieFactory::TENANT_CONTINUATION);
        $this->assertNotNull($continuation);
        $this->assertHostOnlyBindingCookie($continuation);
        $this->assertNull($this->cookieHeaderNamed($headers, SsoBrowserBindingCookieFactory::AUTH_BINDING));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));

        $this->assertSame(1, SsoAuthenticationTransaction::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function auth_host_initiate_sets_binding_cookie_and_redirects_to_idp(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $start = $this->call(
            'GET',
            'https://'.$host.'/auth/enterprise-sso/start',
            server: ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on']
        );
        $start->assertRedirect();
        $initiateUrl = $start->headers->get('Location');
        $this->assertIsString($initiateUrl);

        $this->mock(SsoAuthService::class, function ($mock) use ($tenant) {
            $mock->shouldReceive('assertTenantMayStartSso')->andReturnNull();
            $mock->shouldReceive('buildHostAuthorizationRedirectUrl')
                ->once()
                ->withArgs(function (Tenant $bound, array $materials) use ($tenant) {
                    return $bound->id === $tenant->id
                        && isset($materials['state'], $materials['nonce'], $materials['pkce_challenge']);
                })
                ->andReturn('https://idp.example.com/authorize?state=central');
        });

        $response = $this->call(
            'GET',
            $initiateUrl,
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
                'HTTPS' => 'on',
            ]
        );

        $response->assertRedirect('https://idp.example.com/authorize?state=central');
        $headers = $this->setCookieHeaders($response);
        $binding = $this->cookieHeaderNamed($headers, SsoBrowserBindingCookieFactory::AUTH_BINDING);
        $this->assertNotNull($binding);
        $this->assertHostOnlyBindingCookie($binding);
        $this->assertNull($this->cookieHeaderNamed($headers, SsoBrowserBindingCookieFactory::TENANT_CONTINUATION));

        $txn = SsoAuthenticationTransaction::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame(SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK, $txn->status);
        $this->assertNotNull($txn->auth_binding_secret_hash);
        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function sibling_hosts_cannot_set_each_others_binding_cookies(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $start = $this->call(
            'GET',
            'https://'.$host.'/auth/enterprise-sso/start',
            server: ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on']
        );
        $this->assertNull($this->cookieHeaderNamed(
            $this->setCookieHeaders($start),
            SsoBrowserBindingCookieFactory::AUTH_BINDING
        ));

        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $ref = $query['t'] ?? '';

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('assertTenantMayStartSso')->andReturnNull();
            $mock->shouldReceive('buildHostAuthorizationRedirectUrl')->andReturn('https://idp.example.com/authorize');
        });

        $initiate = $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t='.rawurlencode($ref),
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        );
        $this->assertNull($this->cookieHeaderNamed(
            $this->setCookieHeaders($initiate),
            SsoBrowserBindingCookieFactory::TENANT_CONTINUATION
        ));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function missing_expired_consumed_or_invalid_initiation_reference_fails_closed(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $service = app(AuthenticationTransactionService::class);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($tenant);
        $this->assertNotNull($versionId);

        $created = $service->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $tenant->slug.'.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
        ]);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t=not-a-valid-ref',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $badSecret = explode('.', $created['initiation_reference'], 2)[0].'.wrong-secret';
        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t='.rawurlencode($badSecret),
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $created['transaction']->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t='.rawurlencode($created['initiation_reference']),
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $pending = $service->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $tenant->slug.'.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'tenant_continuation_secret' => SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES),
        ]);
        $service->attachAuthBinding(
            $pending['transaction'],
            SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES)
        );

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t='.rawurlencode($pending['initiation_reference']),
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function browser_supplied_tenant_and_oidc_control_params_are_ignored(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $start = $this->call(
            'GET',
            'https://'.$host.'/auth/enterprise-sso/start?tenant_id='.Str::uuid()
                .'&client_id=evil&redirect_uri=https://evil.example/cb'
                .'&scope=openid&code_challenge=evil&code_challenge_method=plain&nonce=evil',
            server: ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on']
        );
        $start->assertRedirect();

        $txn = SsoAuthenticationTransaction::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame((string) $tenant->id, $txn->tenant_id);
        $this->assertSame($host, $txn->destination_host);

        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $ref = $query['t'] ?? '';

        $this->mock(SsoAuthService::class, function ($mock) use ($tenant) {
            $mock->shouldReceive('assertTenantMayStartSso')->andReturnNull();
            $mock->shouldReceive('buildHostAuthorizationRedirectUrl')
                ->once()
                ->withArgs(fn (Tenant $bound) => $bound->id === $tenant->id)
                ->andReturn('https://idp.example.com/authorize?from=server');
        });

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/initiate?t='.rawurlencode($ref)
                .'&tenant_id='.Str::uuid()
                .'&client_id=evil&redirect_uri=https://evil.example/cb'
                .'&code_challenge=evil&nonce=browser',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertRedirect('https://idp.example.com/authorize?from=server');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function path_era_sso_routes_are_unregistered_on_host_and_remain_404(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('identity.sso.redirect'),
            'Path-era identity.sso.redirect must not be registered on Host'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('identity.sso.callback'),
            'Path-era identity.sso.callback must not be registered on Host'
        );

        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $this->call(
            'GET',
            'https://'.$host.'/auth/sso/redirect',
            server: ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on']
        )->assertNotFound();

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldNotReceive('completeCallback');
            $mock->shouldNotReceive('buildHostAuthorizationRedirectUrl');
            $mock->shouldNotReceive('buildAuthorizationRedirectUrl');
        });

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/sso/callback?code=x&state=y',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function start_is_unavailable_on_auth_host_and_initiate_unavailable_on_tenant_host(): void
    {
        $tenant = $this->createHostTenantWithSso();
        $host = $tenant->slug.'.jabal.test';

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/start',
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertNotFound();

        $this->call(
            'GET',
            'https://'.$host.'/auth/enterprise-sso/initiate?t=anything',
            server: ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'HTTPS' => 'on']
        )->assertNotFound();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function no_callback_token_exchange_handoff_or_session_paths_in_ws3_controllers(): void
    {
        $start = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoStartController.php'));
        $initiate = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoInitiateController.php'));
        $service = file_get_contents(base_path('Modules/Identity/app/Services/HostEnterpriseSsoInitiationService.php'));

        foreach ([$start, $initiate, $service] as $source) {
            $this->assertStringNotContainsString('completeCallback', $source);
            $this->assertStringNotContainsString('issueHandoff', $source);
            $this->assertStringNotContainsString('consumeHandoff', $source);
            $this->assertStringNotContainsString('Auth::login', $source);
            $this->assertStringNotContainsString('Auth::guard', $source);
            $this->assertStringNotContainsString('UserSession', $source);
            $this->assertStringNotContainsString('LaravelSessionAuthSessionAdapter', $source);
        }
    }
}
