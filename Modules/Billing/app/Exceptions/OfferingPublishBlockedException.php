<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

class OfferingPublishBlockedException extends RuntimeException
{
    /**
     * @param  list<array{lane: string, code: string, message: string}>  $failures
     */
    public function __construct(
        public readonly array $failures,
        string $message = 'Offering publish HARD BLOCKED due to incomplete or invalid publish completeness.',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<array{lane: string, code: string, message: string}>  $failures
     */
    public static function fromFailures(array $failures): self
    {
        $summary = implode('; ', array_map(
            fn (array $f) => "[{$f['lane']}/{$f['code']}] {$f['message']}",
            $failures
        ));

        return new self($failures, 'Offering publish HARD BLOCKED: '.$summary);
    }
}
