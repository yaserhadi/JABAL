<?php

namespace App\Exceptions\Tenancy;

use App\Exceptions\DomainException;

class TenantNotFoundException extends DomainException
{
    public function __construct(
        string $message = 'The requested tenant does not exist',
        int $code = 404,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'TENANT_NOT_FOUND';
    }
}
