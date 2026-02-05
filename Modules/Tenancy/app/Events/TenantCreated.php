<?php

namespace Modules\Tenancy\Events;

use App\Support\Events\DomainEvent;
use Modules\Tenancy\Models\Tenant;

class TenantCreated extends DomainEvent
{
    public function __construct(
        public readonly Tenant $tenant
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
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'type' => $this->tenant->type,
                'slug' => $this->tenant->slug,
            ],
        ];
    }
}
