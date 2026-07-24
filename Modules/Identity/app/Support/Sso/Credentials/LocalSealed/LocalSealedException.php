<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use RuntimeException;

/**
 * Fail-closed errors for local_sealed — messages never include secrets or full references.
 */
final class LocalSealedException extends RuntimeException
{
    public static function failClosed(string $reason): self
    {
        return new self("local_sealed failed closed: {$reason}");
    }
}
