<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Exceptions\SsoClaimsException;

/**
 * Maps IdP claims to Jabal EUID. Does not use email, UPN, display name, or mutable username.
 *
 * Physical storage remains issuer + subject (subject = EUID). No column rename.
 */
final class SsoExternalUserIdentifierMapper
{
    public const FAMILY_OIDC = 'oidc';

    public const FAMILY_GOOGLE = 'google';

    public const FAMILY_OKTA = 'okta';

    public const FAMILY_ENTRA = 'entra';

    /**
     * @param  array<string, mixed>  $idClaims
     * @param  array<string, mixed>|null  $userInfoClaims
     */
    public function map(array $idClaims, ?array $userInfoClaims = null): SsoMappedExternalIdentifier
    {
        $issuer = $this->stringClaim($idClaims, 'iss');
        if ($issuer === null || $issuer === '') {
            throw new SsoClaimsException('Validated ID token must include iss.');
        }

        $family = $this->detectFamily($issuer);

        if ($family === self::FAMILY_ENTRA) {
            $oid = $this->stringClaim($idClaims, 'oid')
                ?? ($userInfoClaims !== null ? $this->stringClaim($userInfoClaims, 'oid') : null);

            if ($oid === null || $oid === '') {
                throw new SsoClaimsException(
                    'Entra ID token must include oid as the provider-stable identifier; sub is not used as EUID.'
                );
            }

            $this->assertNotAttributeAsIdentifier($oid);

            return new SsoMappedExternalIdentifier(
                issuer: $issuer,
                euid: $oid,
                providerFamily: $family,
            );
        }

        $sub = $this->stringClaim($idClaims, 'sub');
        if ($sub === null || $sub === '') {
            throw new SsoClaimsException('Validated ID token must include sub.');
        }

        $this->assertNotAttributeAsIdentifier($sub);

        return new SsoMappedExternalIdentifier(
            issuer: $issuer,
            euid: $sub,
            providerFamily: $family,
        );
    }

    public function detectFamily(string $issuer): string
    {
        $host = strtolower((string) (parse_url($issuer, PHP_URL_HOST) ?: ''));

        if ($host === 'accounts.google.com') {
            return self::FAMILY_GOOGLE;
        }

        if (
            $host === 'login.microsoftonline.com'
            || $host === 'login.microsoft.com'
            || $host === 'sts.windows.net'
            || $host === 'login.windows.net'
        ) {
            return self::FAMILY_ENTRA;
        }

        if (
            str_ends_with($host, '.okta.com')
            || str_ends_with($host, '.oktapreview.com')
            || str_ends_with($host, '.okta-emea.com')
            || $host === 'okta.com'
        ) {
            return self::FAMILY_OKTA;
        }

        return self::FAMILY_OIDC;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function stringClaim(array $claims, string $key): ?string
    {
        if (! isset($claims[$key]) || ! is_string($claims[$key])) {
            return null;
        }

        $value = trim($claims[$key]);

        return $value === '' ? null : $value;
    }

    protected function assertNotAttributeAsIdentifier(string $value): void
    {
        if (str_contains($value, '@')) {
            throw new SsoClaimsException('External identifier must not be an email or UPN.');
        }
    }
}
