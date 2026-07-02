<?php

namespace Modules\Billing\Exceptions;

use App\Exceptions\DomainException;

class InvalidSubscriptionTransitionException extends DomainException
{
    public function __construct(
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(
            "Subscription transition from [{$fromStatus}] to [{$toStatus}] is not allowed."
        );
    }

    public function errorCode(): string
    {
        return 'invalid_subscription_transition';
    }

    public function errorDetails(): array
    {
        return [
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
        ];
    }
}
