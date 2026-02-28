<?php

namespace Modules\Settings\Events;

use App\Support\Events\Concerns\HasTenantContext;
use App\Support\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * SettingUpdated Domain Event.
 *
 * Dispatched when a platform setting is updated.
 * This event captures the setting change context (old and new values)
 * and is dispatched after the database transaction commits to ensure
 * data consistency. Settings are central, so tenant context may be null.
 */
class SettingUpdated extends DomainEvent implements ShouldDispatchAfterCommit
{
    use HasTenantContext;

    /**
     * Create a new SettingUpdated event instance.
     *
     * @param  string  $group  The setting group name
     * @param  string  $key  The setting key
     * @param  mixed  $oldValue  The previous value of the setting
     * @param  mixed  $newValue  The new value of the setting
     */
    public function __construct(
        public readonly string $group,
        public readonly string $key,
        public readonly mixed $oldValue,
        public readonly mixed $newValue
    ) {
        parent::__construct();
    }

    /**
     * Get the event payload as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'tenant_id' => $this->tenantId,
            'actor_id' => $this->actorId,
            'setting' => [
                'group' => $this->group,
                'key' => $this->key,
                'old_value' => $this->oldValue,
                'new_value' => $this->newValue,
            ],
        ];
    }
}
