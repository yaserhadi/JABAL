<?php

namespace App\Support\Contracts\Tenancy;

interface TenantResolverInterface
{
    /**
     * Resolve the current tenant, returns null if no tenant found.
     */
    public function resolve(): ?object;

    /**
     * Resolve the current tenant, throws exception if no tenant found.
     *
     * @throws \App\Exceptions\Tenancy\TenantNotFoundException
     */
    public function resolveOrFail(): object;
}
