<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Support\Str;

/**
 * RFC 7636 PKCE S256 — JABAL generates; Facile package consumes code_verifier only.
 */
final class PkceS256Helper
{
    public function generateVerifier(?int $length = null): string
    {
        $length = $length ?? (int) config('identity.sso.pkce_verifier_length', 64);
        $length = max(43, min(128, $length));

        return Str::random($length);
    }

    public function challengeForVerifier(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * @return array{verifier: string, challenge: string, method: string}
     */
    public function generatePair(?int $verifierLength = null): array
    {
        $verifier = $this->generateVerifier($verifierLength);

        return [
            'verifier' => $verifier,
            'challenge' => $this->challengeForVerifier($verifier),
            'method' => 'S256',
        ];
    }
}
