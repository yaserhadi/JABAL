<?php

namespace App\Support\Events;

use App\Support\Contracts\Events\DomainEventInterface;
use DateTimeImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

abstract class DomainEvent implements DomainEventInterface
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public readonly string $eventId;

    public readonly DateTimeImmutable $occurredAt;

    public readonly ?string $tenantId;

    public readonly ?string $actorId;

    public function __construct()
    {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = new DateTimeImmutable;

        // Auto-capture context from current request
        $this->tenantId = $this->captureTenantId();
        $this->actorId = $this->captureActorId();
    }

    /**
     * Get the event unique identifier.
     */
    public function eventId(): string
    {
        return $this->eventId;
    }

    /**
     * Get when the event occurred.
     */
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Get the tenant ID associated with this event.
     */
    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * Get the actor ID who triggered this event.
     */
    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Get the event payload as an array.
     */
    abstract public function toArray(): array;

    /**
     * Capture current tenant ID from context.
     * Override in subclasses if different behavior needed.
     *
     * PHASE 2: Uses Stancl tenancy() for tenant context.
     */
    protected function captureTenantId(): ?string
    {
        return tenancy()->tenant?->id;
    }

    /**
     * Capture current actor (user) ID from context.
     * Override in subclasses if different behavior needed.
     */
    protected function captureActorId(): ?string
    {
        return auth()->id();
    }
}
