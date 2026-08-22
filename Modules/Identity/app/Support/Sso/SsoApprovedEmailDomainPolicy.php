<?php

namespace Modules\Identity\Support\Sso;

/**
 * Connection-scoped approved SSO email domains.
 * Domain match is an additional allow condition only — never User discovery, JIT, or linking authority.
 */
final class SsoApprovedEmailDomainPolicy
{
    /**
     * @param  list<mixed>  $raw
     * @return list<string>
     */
    public static function normalizeList(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $domain = strtolower(trim($item));
            $domain = ltrim($domain, '@');
            $domain = rtrim($domain, '.');
            if ($domain === '' || str_contains($domain, '@') || str_contains($domain, '/') || str_contains($domain, ' ')) {
                continue;
            }
            $out[] = $domain;
        }

        return array_values(array_unique($out));
    }

    /**
     * Empty / missing configuration fails closed (no implicit allow-all).
     *
     * @param  list<string>  $approvedDomains
     */
    public static function allows(string $canonicalUserEmail, array $approvedDomains): bool
    {
        $normalized = self::normalizeList($approvedDomains);
        if ($normalized === []) {
            return false;
        }

        $domain = SsoCanonicalEmail::domain($canonicalUserEmail);
        if ($domain === null) {
            return false;
        }

        foreach ($normalized as $allowed) {
            if (hash_equals($allowed, $domain)) {
                return true;
            }
        }

        return false;
    }
}
