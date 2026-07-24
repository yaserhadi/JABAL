<?php

namespace Modules\Identity\Support\Sso\Credentials;

use RuntimeException;

final class CredentialResolutionException extends RuntimeException
{
    public static function failClosed(string $reason): self
    {
        return new self("IdP credential resolution failed closed: {$reason}");
    }
}
