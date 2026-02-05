<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\DomainException;

class TenantContextMissingException extends DomainException
{
    public function __construct(
        string $message = 'Tenant context is required but not set',
        int $code = 500,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'TENANT_CONTEXT_MISSING';
    }
}
