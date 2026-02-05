<?php

namespace Modules\Settings\Events;

use App\Support\Events\DomainEvent;

class SettingUpdated extends DomainEvent
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $oldValue,
        public readonly mixed $newValue,
        public readonly string $group = 'general'
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
            'setting' => [
                'key' => $this->key,
                'group' => $this->group,
                'old_value' => $this->oldValue,
                'new_value' => $this->newValue,
            ],
        ];
    }
}
