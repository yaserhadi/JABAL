<?php

namespace Tests\Support;

use Facile\OpenIDClient\Client\ClientInterface;
use Illuminate\Contracts\Session\Session;
use Mockery;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\OidcAuthorizationGateway;
use Modules\Identity\Support\Sso\PkceS256Helper;
use Modules\Identity\Support\Sso\SsoClaimsExtractor;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoIssuerUrlValidator;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008 test double — inject a mocked OIDC authorization gateway without network I/O.
 */
class PackageFailingSsoAuthService extends SsoAuthService
{
    public function __construct(
        SsoConfigService $configService,
        SsoIssuerUrlValidator $issuerValidator,
        PkceS256Helper $pkceHelper,
        SsoClaimsExtractor $claimsExtractor,
        SsoIdentityResolver $identityResolver,
        Session $session,
        private readonly OidcAuthorizationGateway $authorizationGateway,
    ) {
        parent::__construct(
            $configService,
            $issuerValidator,
            $pkceHelper,
            $claimsExtractor,
            $identityResolver,
            $session,
        );
    }

    protected function createAuthorizationGateway(): OidcAuthorizationGateway
    {
        return $this->authorizationGateway;
    }

    public function buildOpenIdClient(Tenant $tenant, string $redirectUri): ClientInterface
    {
        return Mockery::mock(ClientInterface::class);
    }
}
