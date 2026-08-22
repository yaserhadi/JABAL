<?php

namespace Modules\Identity\Support\Sso;

/**
 * Fail-closed IdP vs canonical User Email comparison.
 *
 * Normalization is limited to trim + ASCII lowercase of the entire address.
 * Does not rewrite plus-tags, Gmail dots, IDN, or local-part aliases.
 */
final class SsoCanonicalEmail
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function equals(string $left, string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);

        if ($a === '' || $b === '') {
            return false;
        }

        return hash_equals($a, $b);
    }

    public static function domain(string $email): ?string
    {
        $normalized = self::normalize($email);
        $at = strrpos($normalized, '@');
        if ($at === false || $at === 0 || $at === strlen($normalized) - 1) {
            return null;
        }

        return substr($normalized, $at + 1);
    }
}
