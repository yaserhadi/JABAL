<?php

namespace Modules\Identity\Support\Sso;

use InvalidArgumentException;
use RuntimeException;

/**
 * BK-082 / DEC-0024 IH-1 + IH-2: CSPRNG secrets and constant-time proof compare.
 */
final class SsoSecretCrypto
{
    public const STATE_SECRET_BYTES = 32;

    public const NONCE_BYTES = 16;

    public const PKCE_VERIFIER_BYTES = 32;

    public const HANDOFF_SECRET_BYTES = 32;

    public const BINDING_SECRET_BYTES = 32;

    public const INITIATION_SECRET_BYTES = 32;

    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Secret length must be positive.');
        }

        try {
            return random_bytes($length);
        } catch (\Exception $e) {
            throw new RuntimeException('CSPRNG failed to generate secret material.', 0, $e);
        }
    }

    public static function opaqueToken(int $bytes): string
    {
        return self::base64UrlEncode(self::randomBytes($bytes));
    }

    public static function proof(string $secret): string
    {
        return hash('sha256', $secret, false);
    }

    public static function proofsMatch(string $knownProof, string $candidateSecret): bool
    {
        if ($knownProof === '' || $candidateSecret === '') {
            return false;
        }

        return hash_equals($knownProof, self::proof($candidateSecret));
    }

    public static function proofsEqual(string $leftProof, string $rightProof): bool
    {
        if ($leftProof === '' || $rightProof === '') {
            return false;
        }

        return hash_equals($leftProof, $rightProof);
    }

    /**
     * RFC 7636 code_verifier: 43–128 unreserved characters from CSPRNG entropy.
     */
    public static function pkceCodeVerifier(): string
    {
        $verifier = self::base64UrlEncode(self::randomBytes(self::PKCE_VERIFIER_BYTES));

        if (strlen($verifier) < 43 || strlen($verifier) > 128) {
            throw new RuntimeException('Generated PKCE code_verifier length out of RFC 7636 range.');
        }

        return $verifier;
    }

    public static function pkceChallengeS256(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
