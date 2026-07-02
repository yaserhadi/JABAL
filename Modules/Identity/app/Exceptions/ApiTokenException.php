<?php

namespace Modules\Identity\Exceptions;

use Exception;

class ApiTokenException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
