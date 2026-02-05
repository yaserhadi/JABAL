<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\DomainException;

class TenantAccessDeniedException extends DomainException
{
    public function __construct(
        string $message = 'Access to this tenant is denied',
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'TENANT_ACCESS_DENIED';
    }
}
