<?php

namespace App\Support\Contracts\Tenancy;

interface TenantContextInterface
{
    /**
     * Set the current tenant context.
     */
    public function set(?object $tenant): void;

    /**
     * Get the current tenant context.
     */
    public function get(): ?object;

    /**
     * Check if a tenant context is set.
     */
    public function has(): bool;

    /**
     * Clear the current tenant context.
     */
    public function clear(): void;
}
