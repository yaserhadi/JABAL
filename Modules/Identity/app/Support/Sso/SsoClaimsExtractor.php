<?php

namespace Modules\Identity\Support\Sso;

use Facile\OpenIDClient\Token\TokenSetInterface;
use Modules\Identity\Exceptions\SsoClaimsException;

/**
 * ID token claims primary; UserInfo optional with sub cross-check.
 */
final class SsoClaimsExtractor
{
    public function __construct(
        protected SsoExternalUserIdentifierMapper $identifierMapper = new SsoExternalUserIdentifierMapper,
        protected ?SsoSecurityAudit $audit = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $userInfoClaims
     */
    public function extract(TokenSetInterface $tokenSet, ?array $userInfoClaims = null): SsoValidatedClaims
    {
        $idClaims = $tokenSet->claims();

        try {
            $mapped = $this->identifierMapper->map($idClaims, $userInfoClaims);
        } catch (SsoClaimsException $e) {
            $issuer = isset($idClaims['iss']) && is_string($idClaims['iss']) ? $idClaims['iss'] : '';
            $this->audit?->record('sso.trust.euid_mapping_failed', [
                'reason' => 'euid_mapping_failed',
                'status' => 'rejected',
                'purpose' => 'claims_extract',
                'provider_family' => $issuer !== '' ? $this->identifierMapper->detectFamily($issuer) : SsoExternalUserIdentifierMapper::FAMILY_OIDC,
            ]);
            throw $e;
        }

        if ($userInfoClaims !== null && isset($userInfoClaims['sub'])) {
            $idSub = isset($idClaims['sub']) ? (string) $idClaims['sub'] : '';
            if ($idSub === '' || (string) $userInfoClaims['sub'] !== $idSub) {
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
            issuer: $mapped->issuer,
            subject: $mapped->euid,
            email: $email,
            emailVerified: $emailVerified,
            providerFamily: $mapped->providerFamily,
        );
    }
}
