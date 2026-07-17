<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Facile\OpenIDClient\Exception\OAuth2Exception;
use Facile\OpenIDClient\Exception\RuntimeException as OpenIdRuntimeException;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Contracts\Session\Session;
use Mockery;
use Modules\Identity\Models\Membership;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\LaravelSessionAuthSessionAdapter;
use Modules\Identity\Support\Sso\OidcAuthorizationGateway;
use Modules\Identity\Support\Sso\PkceS256Helper;
use Modules\Identity\Support\Sso\SsoClaimsExtractor;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoIssuerUrlValidator;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\ClaimsFailingSsoAuthService;
use Tests\Support\PackageFailingSsoAuthService;
use Tests\TestCase;

/** BK-008 callback security — JABAL handling of package validation failures. */
class SsoCallbackSecurityTest extends TestCase
{
    use GrantsSsoEntitlement;
    use \Tests\Support\SkipsPathEnterpriseSsoUnderHostProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipPathEnterpriseSsoWhenHostProfile();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function createEnabledOrgTenant(): array
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Callback Security User',
            'email' => 'sso-cb-'.uniqid().'@example.com',
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
            'issuer_url' => 'https://example.com',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
        tenancy()->end();

        return [$tenant, $user];
    }

    protected function bindPackageFailingAuthService(OidcAuthorizationGateway $authorizationGateway): PackageFailingSsoAuthService
    {
        $service = new PackageFailingSsoAuthService(
            app(SsoConfigService::class),
            app(SsoIssuerUrlValidator::class),
            app(PkceS256Helper::class),
            app(SsoClaimsExtractor::class),
            app(SsoIdentityResolver::class),
            app(Session::class),
            $authorizationGateway,
        );

        $this->app->instance(SsoAuthService::class, $service);

        return $service;
    }

    #[Test]
    public function authorization_redirect_passes_nonce_to_package(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('getAuthorizationUri')
            ->once()
            ->with(Mockery::any(), Mockery::on(function (array $params): bool {
                return isset($params['nonce']) && is_string($params['nonce']) && $params['nonce'] !== ''
                    && isset($params['state']) && is_string($params['state']) && $params['state'] !== '';
            }))
            ->andReturn('https://idp.example.com/authorize');

        $this->bindPackageFailingAuthService($package);

        $url = app(SsoAuthService::class)->buildAuthorizationRedirectUrl($tenant);

        $this->assertSame('https://idp.example.com/authorize', $url);
        $payload = app('session.store')->get(LaravelSessionAuthSessionAdapter::sessionKey($tenant->id));
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['nonce'] ?? null);
    }

    #[Test]
    public function package_nonce_failure_is_rejected_without_login(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();
        $prepared = app(SsoAuthService::class)->prepareAuthorizationSession($tenant);

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('callback')
            ->once()
            ->andThrow(new OpenIdRuntimeException('Nonce mismatch'));

        $this->bindPackageFailingAuthService($package);

        $session = $this->app['session.store'];
        $sessionIdBefore = $session->getId();

        $response = $this->get('/auth/sso/callback?code=abc&state='.urlencode($prepared['state']));
        $response->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertNull(session('tenant_id'));
        $this->assertSame($sessionIdBefore, $session->getId());
    }

    #[Test]
    public function package_audience_failure_is_rejected_without_login(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();
        $prepared = app(SsoAuthService::class)->prepareAuthorizationSession($tenant);

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('callback')
            ->once()
            ->andThrow(new OAuth2Exception('invalid_token', 'Invalid audience'));

        $this->bindPackageFailingAuthService($package);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($prepared['state']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertNull(session('tenant_id'));
    }

    #[Test]
    public function package_client_id_failure_is_rejected_without_login(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();
        $prepared = app(SsoAuthService::class)->prepareAuthorizationSession($tenant);

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('callback')
            ->once()
            ->andThrow(new OAuth2Exception('invalid_client', 'client_id mismatch'));

        $this->bindPackageFailingAuthService($package);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($prepared['state']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertNull(session('tenant_id'));
    }

    #[Test]
    public function userinfo_sub_mismatch_is_rejected_without_login(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();
        $prepared = app(SsoAuthService::class)->prepareAuthorizationSession($tenant);

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => 'https://example.com',
            'sub' => 'id-token-sub',
        ]);

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('callback')->once()->andReturn($tokenSet);

        $service = new ClaimsFailingSsoAuthService(
            app(SsoConfigService::class),
            app(SsoIssuerUrlValidator::class),
            app(PkceS256Helper::class),
            app(SsoClaimsExtractor::class),
            app(SsoIdentityResolver::class),
            app(Session::class),
            $package,
        );
        $this->app->instance(SsoAuthService::class, $service);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($prepared['state']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertNull(session('tenant_id'));
    }

    #[Test]
    public function complete_callback_returns_protocol_error_for_package_failures(): void
    {
        [$tenant] = $this->createEnabledOrgTenant();
        $prepared = app(SsoAuthService::class)->prepareAuthorizationSession($tenant);

        $package = Mockery::mock(OidcAuthorizationGateway::class);
        $package->shouldReceive('callback')
            ->once()
            ->andThrow(new OAuth2Exception('invalid_nonce', 'Nonce validation failed'));

        $this->bindPackageFailingAuthService($package);

        $result = app(SsoAuthService::class)->completeCallback($tenant, [
            'code' => 'auth-code',
            'state' => $prepared['state'],
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('protocol_error', $result->failureReason);
    }

    #[Test]
    public function claims_extractor_never_reads_access_token(): void
    {
        $source = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoClaimsExtractor.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('getAccessToken', $source);
        $this->assertStringNotContainsString('access_token', $source);
    }
}
