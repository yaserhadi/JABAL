<?php

namespace App\Support\Contracts\Events;

interface DomainEventInterface
{
    /**
     * Get the event unique identifier.
     */
    public function eventId(): string;

    /**
     * Get when the event occurred.
     */
    public function occurredAt(): \DateTimeImmutable;

    /**
     * Get the tenant ID associated with this event (if any).
     */
    public function tenantId(): ?string;

    /**
     * Get the actor ID who triggered this event (if any).
     */
    public function actorId(): ?string;

    /**
     * Get the event payload as an array.
     */
    public function toArray(): array;
}
