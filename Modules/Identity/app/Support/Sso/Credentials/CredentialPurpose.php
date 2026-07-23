<?php

namespace Modules\Identity\Support\Sso\Credentials;

/**
 * Protocol purpose for IdP credential resolution (fail-closed purpose gate).
 */
enum CredentialPurpose: string
{
    case OidcClientAuth = 'oidc_client_auth';
    case BackchannelLogoutHs256 = 'backchannel_logout_hs256';
    case BackchannelLogoutJwks = 'backchannel_logout_jwks';
    case PrivateKeyJwtSigning = 'private_key_jwt_signing';
    case ConfigValidation = 'config_validation';

    /**
     * Credential types allowed for this purpose. Empty = never resolve a secret.
     *
     * @return list<string>
     */
    public function allowedCredentialTypes(): array
    {
        return match ($this) {
            self::OidcClientAuth => ['oidc_client_secret'],
            self::BackchannelLogoutHs256 => ['oidc_client_secret'],
            self::BackchannelLogoutJwks => [],
            self::PrivateKeyJwtSigning => ['private_key_jwt'],
            self::ConfigValidation => ['oidc_client_secret', 'private_key_jwt'],
        };
    }

    /**
     * Client authentication methods compatible with this purpose (when provided).
     *
     * @return list<string>|null null = no client-auth constraint for this purpose
     */
    public function allowedClientAuthMethods(): ?array
    {
        return match ($this) {
            self::OidcClientAuth => ['client_secret_basic', 'client_secret_post', 'client_secret_jwt'],
            self::BackchannelLogoutHs256 => ['client_secret_basic', 'client_secret_post', 'client_secret_jwt'],
            self::BackchannelLogoutJwks => null,
            self::PrivateKeyJwtSigning => ['private_key_jwt'],
            self::ConfigValidation => null,
        };
    }

    public function mayResolveSecret(): bool
    {
        return $this->allowedCredentialTypes() !== [];
    }
}
