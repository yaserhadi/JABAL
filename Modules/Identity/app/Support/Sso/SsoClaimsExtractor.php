<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Token\TokenSetInterface;
use Modules\Identity\Exceptions\SsoClaimsException;

/**
 * ID token claims primary; UserInfo optional with sub cross-check.
 */
final class SsoClaimsExtractor
{
    /**
     * @param  array<string, mixed>|null  $userInfoClaims
     */
    public function extract(TokenSetInterface $tokenSet, ?array $userInfoClaims = null): SsoValidatedClaims
    {
        $idClaims = $tokenSet->claims();

        $subject = isset($idClaims['sub']) ? (string) $idClaims['sub'] : '';
        if ($subject === '') {
            throw new SsoClaimsException('Validated ID token must include sub.');
        }

        $issuer = isset($idClaims['iss']) ? (string) $idClaims['iss'] : '';
        if ($issuer === '') {
            throw new SsoClaimsException('Validated ID token must include iss.');
        }

        if ($userInfoClaims !== null && isset($userInfoClaims['sub'])) {
            if ((string) $userInfoClaims['sub'] !== $subject) {
                throw new SsoClaimsException('UserInfo sub does not match ID token sub.');
            }
        }

        $email = null;
        if (isset($idClaims['email'])) {
            $email = (string) $idClaims['email'];
        } elseif ($userInfoClaims !== null && isset($userInfoClaims['email'])) {
            $email = (string) $userInfoClaims['email'];
        }

        $emailVerified = null;
        if (array_key_exists('email_verified', $idClaims)) {
            $emailVerified = (bool) $idClaims['email_verified'];
        } elseif ($userInfoClaims !== null && array_key_exists('email_verified', $userInfoClaims)) {
            $emailVerified = (bool) $userInfoClaims['email_verified'];
        }

        return new SsoValidatedClaims(
            issuer: $issuer,
            subject: $subject,
            email: $email,
            emailVerified: $emailVerified,
        );
    }
}
