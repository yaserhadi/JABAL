<?php

namespace Modules\Billing\Exceptions;

use RuntimeException;

/**
 * BK-115 PR-04: Incomplete Offering cannot use normal Publish — explicit override required.
 *
 * @phpstan-type Warning array{lane: string, code: string, message: string}
 */
class OfferingPublishOverrideRequiredException extends RuntimeException
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly array $warnings,
        string $message = 'Offering publish is NOT RECOMMENDED due to incomplete completeness. Explicit Publish Anyway (override) is required.',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<Warning>  $warnings
     */
    public static function fromWarnings(array $warnings): self
    {
        $summary = implode('; ', array_map(
            fn (array $w) => "[{$w['lane']}/{$w['code']}] {$w['message']}",
            $warnings
        ));

        return new self(
            $warnings,
            'Offering publish NOT RECOMMENDED (explicit override required): '.$summary
        );
    }
}
