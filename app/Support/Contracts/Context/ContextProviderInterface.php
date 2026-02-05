<?php

namespace App\Support\Contracts\Context;

interface ContextProviderInterface
{
    /**
     * Get the current request ID.
     */
    public function requestId(): ?string;

    /**
     * Get the current actor (user or system).
     */
    public function actor(): ?object;

    /**
     * Get the current tenant.
     */
    public function tenant(): ?object;

    /**
     * Get the execution context (web, job, cli, test).
     */
    public function executionMode(): string;

    /**
     * Get all context data as an array.
     */
    public function toArray(): array;
}
