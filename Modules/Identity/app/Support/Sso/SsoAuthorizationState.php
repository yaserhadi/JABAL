<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\SsoSecurityException;

/**
 * Encrypted OIDC state parameter — carries tenant_id for callback tenancy (never trust query tenant_id).
 */
final class SsoAuthorizationState
{
    /**
     * @return array{tenant_id: string, csrf: string, exp: int}
     */
    public static function mint(string $tenantId): array
    {
        $ttl = (int) config('identity.sso.state_ttl', 600);

        return [
            'tenant_id' => $tenantId,
            'csrf' => Str::random(40),
            'exp' => now()->addSeconds($ttl)->timestamp,
        ];
    }

    public static function encode(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{tenant_id: string, csrf: string, exp: int}
     */
    public static function parse(string $state): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new SsoSecurityException('Invalid SSO state.');
        }

        if (
            ! is_array($decoded)
            || ! isset($decoded['tenant_id'], $decoded['csrf'], $decoded['exp'])
            || ! is_string($decoded['tenant_id'])
            || ! is_string($decoded['csrf'])
            || ! is_int($decoded['exp'])
        ) {
            throw new SsoSecurityException('Invalid SSO state.');
        }

        if ($decoded['exp'] < now()->timestamp) {
            throw new SsoSecurityException('SSO state expired.');
        }

        return $decoded;
    }

    public static function tenantIdFromStateParameter(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        try {
            return self::parse($state)['tenant_id'];
        } catch (SsoSecurityException) {
            return null;
        }
    }
}
