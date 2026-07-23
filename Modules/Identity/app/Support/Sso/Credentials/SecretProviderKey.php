<?php

namespace Modules\Identity\Support\Sso\Credentials;

use InvalidArgumentException;

/**
 * Canonical provider key: lowercase, trimmed, strict pattern, collision-safe.
 */
final class SecretProviderKey
{
    /** Lowercase letter start, then lowercase alnum / underscore only. */
    private const PATTERN = '/^[a-z][a-z0-9_]*$/';

    public static function canonicalize(string $key): string
    {
        $normalized = strtolower(trim($key));

        if ($normalized === '' || preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException('Invalid secret provider key.');
        }

        return $normalized;
    }
}
