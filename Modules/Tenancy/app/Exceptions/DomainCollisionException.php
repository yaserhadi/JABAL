<?php

declare(strict_types=1);

namespace Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a platform-subdomain domain label collides with another Tenant's reservation.
 */
final class DomainCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $domainLabel,
        public readonly ?string $existingTenantId = null,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Platform subdomain [{$domainLabel}] is already reserved"
                    .($existingTenantId !== null ? " by tenant [{$existingTenantId}]" : '').'.'
        );
    }
}
