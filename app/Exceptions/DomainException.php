<?php

namespace App\Exceptions;

use Exception;

abstract class DomainException extends Exception
{
    /**
     * Get the error code for API responses.
     */
    abstract public function errorCode(): string;

    /**
     * Get additional error details.
     */
    public function errorDetails(): array
    {
        return [];
    }

    /**
     * Convert exception to array for API response.
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
                'details' => $this->errorDetails(),
            ],
        ];
    }
}
