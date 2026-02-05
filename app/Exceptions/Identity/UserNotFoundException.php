<?php

namespace App\Exceptions\Identity;

use App\Exceptions\DomainException;

class UserNotFoundException extends DomainException
{
    public function __construct(
        string $message = 'The requested user does not exist',
        int $code = 404,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'USER_NOT_FOUND';
    }
}
