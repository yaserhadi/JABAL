<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Contracts\Session\Session;

/**
 * BK-082 WS5: short-lived Tenant-bound MFA continuation after Handoff consume (pre-UserSession).
 */
final class SsoMfaContinuation
{
    public const SESSION_KEY = 'sso.mfa_continuation';

    public const DEFER_USER_SESSION_KEY = 'sso.defer_user_session';

    public static function ttlSeconds(): int
    {
        return max(60, (int) config('identity.sso.mfa_continuation_ttl', 300));
    }

    /**
     * @param  array{user_id: string, tenant_id: string, post_login_path: string, handoff_id: string}  $payload
     */
    public static function store(Session $session, array $payload): void
    {
        $session->put(self::SESSION_KEY, [
            'user_id' => $payload['user_id'],
            'tenant_id' => $payload['tenant_id'],
            'post_login_path' => $payload['post_login_path'],
            'handoff_id' => $payload['handoff_id'],
            'expires_at' => now()->addSeconds(self::ttlSeconds())->timestamp,
        ]);
        $session->put(self::DEFER_USER_SESSION_KEY, true);
    }

    /**
     * @return array{user_id: string, tenant_id: string, post_login_path: string, handoff_id: string, expires_at: int}|null
     */
    public static function pullValid(Session $session, string $tenantId, string $userId): ?array
    {
        $payload = $session->get(self::SESSION_KEY);
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['tenant_id'] ?? null) !== $tenantId || ($payload['user_id'] ?? null) !== $userId) {
            return null;
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt < now()->timestamp) {
            self::clear($session);

            return null;
        }

        return [
            'user_id' => (string) $payload['user_id'],
            'tenant_id' => (string) $payload['tenant_id'],
            'post_login_path' => (string) ($payload['post_login_path'] ?? '/dashboard'),
            'handoff_id' => (string) ($payload['handoff_id'] ?? ''),
            'expires_at' => $expiresAt,
        ];
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
        $session->forget(self::DEFER_USER_SESSION_KEY);
    }

    public static function shouldDeferUserSession(Session $session): bool
    {
        return (bool) $session->get(self::DEFER_USER_SESSION_KEY);
    }
}
