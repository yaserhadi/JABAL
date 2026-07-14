<?php

namespace Modules\Tenancy\Events;

use App\Support\Events\Concerns\HasTenantContext;
use App\Support\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Modules\Tenancy\Models\Tenant;

/**
 * TenantCreated Domain Event.
 *
 * Dispatched when a new tenant is created in the system.
 * This event captures the tenant creation context and is dispatched
 * after the database transaction commits to ensure data consistency.
 */
class TenantCreated extends DomainEvent implements ShouldDispatchAfterCommit
{
    use HasTenantContext;

    /**
     * Create a new TenantCreated event instance.
     *
     * @param  Tenant  $tenant  The newly created tenant
     */
    public function __construct(
        public readonly Tenant $tenant
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
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ],
        ];
    }
}
