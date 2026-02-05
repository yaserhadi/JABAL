<?php

namespace Modules\Identity\Events;

use App\Models\User;
use App\Support\Events\DomainEvent;

class UserRegistered extends DomainEvent
{
    public function __construct(
        public readonly User $user
    ) {
        parent::__construct();
    }

    /**
     * Get the event payload as an array.
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'tenant_id' => $this->tenantId,
            'actor_id' => $this->actorId,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
        ];
    }
}
