<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a supported application path attempts hard/force Tenant deletion
 * while domain release policy (BK-075) is not yet defined.
 */
final class TenantHardDeleteProhibitedException extends RuntimeException
{
    public function __construct(string $message = 'Hard/force Tenant deletion is prohibited until BK-075 defines domain release policy.')
    {
        parent::__construct($message);
    }
}
