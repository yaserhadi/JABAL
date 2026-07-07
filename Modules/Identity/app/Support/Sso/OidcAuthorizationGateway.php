<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;

/**
 * BK-008: JABAL-owned boundary for Facile authorization/token exchange (testable seam).
 */
interface OidcAuthorizationGateway
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function getAuthorizationUri(ClientInterface $client, array $params = []): string;

    /**
     * @param  array<string, mixed>  $params
     */
    public function callback(
        ClientInterface $client,
        array $params,
        ?string $redirectUri = null,
        ?AuthSessionInterface $authSession = null,
        ?int $maxAge = null,
    ): TokenSetInterface;
}
