<?php

namespace Modules\Identity\Support\Sso;

/**
 * BK-082 WS7 / IH-5: redact prohibited SSO material from observability payloads.
 */
final class SsoObservabilityRedactor
{
    /** @var list<string> */
    private const PROHIBITED_KEYS = [
        'authorization_code',
        'code',
        'state',
        'nonce',
        'code_verifier',
        'pkce_verifier',
        'access_token',
        'refresh_token',
        'id_token',
        'logout_token',
        'client_secret',
        'client_secret_encrypted',
        'credential_reference',
        'unseal_key',
        'plaintext',
        'client_assertion',
        'private_key',
        'cookie',
        'cookies',
        'authorization',
        'auth_binding',
        'tenant_continuation',
        'handoff_secret',
        'handoff_reference',
        'initiation_reference',
        'password',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;
            if (self::isProhibitedKey($keyStr)) {
                $out[$keyStr] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $out[$keyStr] = self::redact($value);

                continue;
            }
            if (is_string($value) && self::looksLikeSecretMaterial($value)) {
                $out[$keyStr] = '[redacted]';

                continue;
            }
            $out[$keyStr] = $value;
        }

        return $out;
    }

    public static function redactString(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\b(code|state|nonce|access_token|refresh_token|id_token|logout_token)=([^\s&]+)/i', '$1=[redacted]', $message) ?? $message;

        return $message;
    }

    protected static function isProhibitedKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::PROHIBITED_KEYS as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function looksLikeSecretMaterial(string $value): bool
    {
        // Opaque tokens / JWTs (three base64url segments)
        if (substr_count($value, '.') === 2 && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value) === 1) {
            return true;
        }

        if (strlen($value) < 24) {
            return false;
        }

        return false;
    }
}
