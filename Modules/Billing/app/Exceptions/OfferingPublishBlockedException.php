<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

/**
 * BK-115 PR-04: Non-overridable structural/integrity publish denial.
 *
 * @phpstan-type Failure array{lane: string, code: string, message: string}
 */
class OfferingPublishBlockedException extends RuntimeException
{
    /**
     * @param  list<Failure>  $failures
     */
    public function __construct(
        public readonly array $failures,
        string $message = 'Offering publish blocked by structural integrity constraints.',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<Failure>  $failures
     */
    public static function fromFailures(array $failures): self
    {
        $summary = implode('; ', array_map(
            fn (array $f) => "[{$f['lane']}/{$f['code']}] {$f['message']}",
            $failures
        ));

        return new self($failures, 'Offering publish INTEGRITY BLOCKED: '.$summary);
    }
}
