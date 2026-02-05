<?php

namespace App\Support\Events\Concerns;

trait HasTenantContext
{
    /**
     * Override tenant ID capture to use a specific tenant.
     */
    public function forTenant(?object $tenant): static
    {
        $this->tenantId = $tenant?->id;

        return $this;
    }

    /**
     * Override actor ID capture to use a specific actor.
     */
    public function byActor(?object $actor): static
    {
        $this->actorId = $actor?->id;

        return $this;
    }
}
