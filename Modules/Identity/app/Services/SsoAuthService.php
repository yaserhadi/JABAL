<?php

namespace Modules\Identity\Services;

use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\IssuerInterface;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\URL;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Support\Sso\FacileOidcAuthorizationGateway;
use Modules\Identity\Support\Sso\LaravelSessionAuthSessionAdapter;
use Modules\Identity\Support\Sso\OidcAuthorizationGateway;
use Modules\Identity\Support\Sso\PkceS256Helper;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Identity\Support\Sso\SsoClaimsExtractor;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoIssuerUrlValidator;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Tenancy\Models\Tenant;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * BK-008: OIDC protocol orchestration — no web guard login, no callback routes, no token persistence.
 */
class SsoAuthService
{
    public function __construct(
        protected SsoConfigService $configService,
        protected SsoIssuerUrlValidator $issuerValidator,
        protected PkceS256Helper $pkceHelper,
        protected SsoClaimsExtractor $claimsExtractor,
        protected SsoIdentityResolver $identityResolver,
        protected Session $session,
    ) {}

    public function assertTenantMayStartSso(Tenant $tenant): void
    {
        if ($tenant->status !== 'active') {
            throw new SsoSecurityException('Tenant is not active.');
        }

        if (! $this->configService->isOperationalForTenant($tenant)) {
            throw new SsoSecurityException('Tenant SSO is not enabled or not fully configured.');
        }
    }

    /**
     * Validate issuer URL, discover metadata with restricted HTTP client, verify issuer match.
     */
    public function buildIssuer(Tenant $tenant): IssuerInterface
    {
        $configured = $this->configService->getConfiguredIssuer($tenant);

        if ($configured === null) {
            throw new SsoSecurityException('Tenant SSO issuer is not configured.');
        }

        $safeIssuer = $this->issuerValidator->validateConfiguredIssuer($configured);

        $metadataBuilder = (new MetadataProviderBuilder)
            ->setHttpClient($this->createDiscoveryHttpClient());

        $issuerBuilder = (new IssuerBuilder)
            ->setMetadataProviderBuilder($metadataBuilder);

        $issuer = $issuerBuilder->build($safeIssuer);

        $this->issuerValidator->assertDiscoveredIssuerMatches(
            $safeIssuer,
            (string) $issuer->getMetadata()->getIssuer()
        );

        return $issuer;
    }

    /**
     * Prepare PKCE + state/nonce session payload for a future authorization redirect.
     *
     * @return array{session: AuthSessionInterface, code_challenge: string, code_challenge_method: string}
     */
    public function prepareAuthorizationSession(Tenant $tenant): array
    {
        $this->assertTenantMayStartSso($tenant);

        $pair = $this->pkceHelper->generatePair();
        $statePayload = SsoAuthorizationState::mint($tenant->id);
        $encodedState = SsoAuthorizationState::encode($statePayload);

        $adapter = new LaravelSessionAuthSessionAdapter($this->session, $tenant->id);
        $adapter->initializeForAuthorization($pair['verifier'], $encodedState);

        return [
            'session' => $adapter,
            'code_challenge' => $pair['challenge'],
            'code_challenge_method' => $pair['method'],
            'state' => $encodedState,
        ];
    }

    public function callbackRedirectUri(): string
    {
        return URL::route('identity.sso.callback', [], true);
    }

    public function buildAuthorizationRedirectUrl(Tenant $tenant): string
    {
        $prepared = $this->prepareAuthorizationSession($tenant);
        $redirectUri = $this->resolveRedirectUri($tenant);
        $client = $this->buildOpenIdClient($tenant, $redirectUri);
        $config = $this->configService->getForTenant($tenant);
        $scopes = is_array($config['scopes'] ?? null)
            ? $config['scopes']
            : config('identity.sso.default_scopes', ['openid', 'profile', 'email']);

        $authorizationService = $this->createAuthorizationGateway();

        /** @var LaravelSessionAuthSessionAdapter $authSession */
        $authSession = $prepared['session'];

        return $authorizationService->getAuthorizationUri($client, [
            'scope' => implode(' ', $scopes),
            'state' => $authSession->getState(),
            'nonce' => $authSession->getNonce(),
            'code_challenge' => $prepared['code_challenge'],
            'code_challenge_method' => $prepared['code_challenge_method'],
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Protocol callback + identity resolution only — no web guard login.
     *
     * @param  array{code?: string|null, state?: string|null}  $callbackParams
     */
    public function completeCallback(Tenant $tenant, array $callbackParams): SsoIdentityResolutionResult
    {
        $this->assertTenantMayStartSso($tenant);

        $receivedState = isset($callbackParams['state']) ? (string) $callbackParams['state'] : '';
        $code = isset($callbackParams['code']) ? (string) $callbackParams['code'] : '';

        if ($receivedState === '' || $code === '') {
            return SsoIdentityResolutionResult::failed('invalid_callback');
        }

        try {
            $parsedState = SsoAuthorizationState::parse($receivedState);
        } catch (SsoSecurityException) {
            return SsoIdentityResolutionResult::failed('invalid_state');
        }

        if ($parsedState['tenant_id'] !== $tenant->id) {
            return SsoIdentityResolutionResult::failed('tenant_mismatch');
        }

        $authSession = $this->pullAuthorizationSession($tenant);

        if ($authSession === null || $authSession->getState() !== $receivedState) {
            return SsoIdentityResolutionResult::failed('invalid_state');
        }

        $redirectUri = $this->resolveRedirectUri($tenant);
        $result = SsoIdentityResolutionResult::failed('protocol_error');

        try {
            $client = $this->buildOpenIdClient($tenant, $redirectUri);
            $authorizationService = $this->createAuthorizationGateway();

            $tokenSet = $authorizationService->callback(
                $client,
                [
                    'code' => $code,
                    'state' => $receivedState,
                ],
                $redirectUri,
                $authSession,
            );

            $claims = $this->extractValidatedClaims($tokenSet);
            $result = $this->resolveIdentity($tenant, $claims);
        } catch (\Throwable) {
            $result = SsoIdentityResolutionResult::failed('protocol_error');
        } finally {
            $this->clearAuthorizationSession($tenant);
        }

        return $result;
    }

    protected function resolveRedirectUri(Tenant $tenant): string
    {
        $config = $this->configService->getForTenant($tenant);
        $configured = $config['redirect_uri'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->callbackRedirectUri();
    }

    public function pullAuthorizationSession(Tenant $tenant): ?LaravelSessionAuthSessionAdapter
    {
        return LaravelSessionAuthSessionAdapter::pull($this->session, $tenant->id);
    }

    public function clearAuthorizationSession(Tenant $tenant): void
    {
        $this->session->forget(LaravelSessionAuthSessionAdapter::sessionKey($tenant->id));
    }

    /**
     * @param  array<string, mixed>|null  $userInfoClaims
     */
    public function extractValidatedClaims(TokenSetInterface $tokenSet, ?array $userInfoClaims = null): SsoValidatedClaims
    {
        return $this->claimsExtractor->extract($tokenSet, $userInfoClaims);
    }

    public function resolveIdentity(
        Tenant $tenant,
        SsoValidatedClaims $claims,
    ): SsoIdentityResolutionResult {
        $configuredIssuer = $this->configService->getConfiguredIssuer($tenant);

        if ($configuredIssuer === null) {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH);
        }

        $safeIssuer = $this->issuerValidator->validateConfiguredIssuer($configuredIssuer);

        return $this->identityResolver->resolve($tenant, $claims, $safeIssuer);
    }

    /**
     * Build OpenID client metadata for protocol use (no login side effects).
     *
     * @param  list<string>  $scopes
     */
    public function buildClientMetadata(Tenant $tenant, string $redirectUri, array $scopes): ClientMetadata
    {
        $config = $this->configService->getForTenant($tenant);
        $clientId = $config['client_id'] ?? null;

        if (! is_string($clientId) || $clientId === '') {
            throw new SsoSecurityException('Tenant SSO client_id is not configured.');
        }

        return ClientMetadata::fromArray([
            'client_id' => $clientId,
            'redirect_uris' => [$redirectUri],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);
    }

    public function buildOpenIdClient(Tenant $tenant, string $redirectUri): \Facile\OpenIDClient\Client\ClientInterface
    {
        $issuer = $this->buildIssuer($tenant);
        $config = $this->configService->getForTenant($tenant);
        $clientId = $config['client_id'] ?? null;
        $secret = $this->configService->getDecryptedClientSecret($tenant);

        if (! is_string($clientId) || $clientId === '' || $secret === null || $secret === '') {
            throw new SsoSecurityException('Tenant SSO client credentials are not configured.');
        }

        $metadata = ClientMetadata::fromArray([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'redirect_uris' => [$redirectUri],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);

        return (new ClientBuilder)
            ->setIssuer($issuer)
            ->setClientMetadata($metadata)
            ->setHttpClient($this->createDiscoveryHttpClient())
            ->build();
    }

    protected function createAuthorizationGateway(): OidcAuthorizationGateway
    {
        $authorizationService = (new AuthorizationServiceBuilder)
            ->setHttpClient($this->createDiscoveryHttpClient())
            ->build();

        return new FacileOidcAuthorizationGateway($authorizationService);
    }

    protected function createDiscoveryHttpClient(): ClientInterface
    {
        $timeout = (int) config('identity.sso.discovery_timeout', 10);

        return new Psr18Client(HttpClient::create([
            'timeout' => $timeout,
            'max_redirects' => 0,
        ]));
    }
}
