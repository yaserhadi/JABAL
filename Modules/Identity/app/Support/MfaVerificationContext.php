<?php

namespace Modules\Identity\Support;

/**
 * Session-scoped step-up MFA context for sensitive actions (Track 3B).
 */
class MfaVerificationContext
{
    public const SESSION_KEY = 'mfa_step_up';

    public static function markVerified(string $purpose, int $ttlSeconds = 900): void
    {
        session()->put(self::SESSION_KEY, [
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ]);
    }

    public static function isVerified(string $purpose): bool
    {
        $ctx = session(self::SESSION_KEY);

        if (! is_array($ctx) || ($ctx['purpose'] ?? '') !== $purpose) {
            return false;
        }

        return ($ctx['expires_at'] ?? 0) > now()->timestamp;
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
