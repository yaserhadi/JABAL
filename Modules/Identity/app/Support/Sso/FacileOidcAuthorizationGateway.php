<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;

final class FacileOidcAuthorizationGateway implements OidcAuthorizationGateway
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function getAuthorizationUri(ClientInterface $client, array $params = []): string
    {
        return $this->authorizationService->getAuthorizationUri($client, $params);
    }

    public function callback(
        ClientInterface $client,
        array $params,
        ?string $redirectUri = null,
        ?AuthSessionInterface $authSession = null,
        ?int $maxAge = null,
    ): TokenSetInterface {
        return $this->authorizationService->callback($client, $params, $redirectUri, $authSession, $maxAge);
    }
}
