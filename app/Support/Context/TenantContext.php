<?php

namespace App\Support\Context;

use App\Support\Contracts\Tenancy\TenantContextInterface;

class TenantContext implements TenantContextInterface
{
    private static ?self $instance = null;

    private ?object $tenant = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Set the current tenant context.
     */
    public function set(?object $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Get the current tenant context.
     */
    public function get(): ?object
    {
        return $this->tenant;
    }

    /**
     * Check if a tenant context is set.
     */
    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Clear the current tenant context.
     */
    public function clear(): void
    {
        $this->tenant = null;
    }

    /**
     * Get tenant ID.
     */
    public function id(): ?string
    {
        return $this->tenant?->id;
    }

    /**
     * Get tenant ID (alias).
     */
    public function getTenantId(): ?string
    {
        return $this->id();
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->id(),
        ];
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
