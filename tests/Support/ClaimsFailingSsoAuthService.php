<?php

namespace Tests\Support;

use Facile\OpenIDClient\Token\TokenSetInterface;
use Modules\Identity\Exceptions\SsoClaimsException;
use Modules\Identity\Support\Sso\SsoValidatedClaims;

/**
 * BK-008 test double — simulate claims extraction failure after package callback succeeds.
 */
final class ClaimsFailingSsoAuthService extends PackageFailingSsoAuthService
{
    public function extractValidatedClaims(TokenSetInterface $tokenSet, ?array $userInfoClaims = null): SsoValidatedClaims
    {
        throw new SsoClaimsException('UserInfo sub does not match ID token sub.');
    }
}
