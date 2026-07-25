<?php

namespace Modules\Identity\Support\Sso;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * BK-082 / IH-3: host-only browser binding cookies (no Domain attribute).
 */
final class SsoBrowserBindingCookieFactory
{
    public const TENANT_CONTINUATION = 'jabal_sso_tenant_continuation';

    public const AUTH_BINDING = 'jabal_sso_auth_binding';

    /** BK-099: browser binding for invitation → login resume. */
    public const ENROLLMENT_LOGIN_RESUME = 'jabal_sso_enrollment_login_resume';

    /** BK-099: browser binding for enrollment continuation consume. */
    public const ENROLLMENT_BROWSER_BINDING = 'jabal_sso_enrollment_browser_binding';

    public static function make(string $name, string $value, int $ttlSeconds, bool $secure): Cookie
    {
        return new Cookie(
            name: $name,
            value: $value,
            expire: time() + max(1, $ttlSeconds),
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public static function clear(string $name, bool $secure): Cookie
    {
        return new Cookie(
            name: $name,
            value: '',
            expire: time() - 3600,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
