<?php

namespace Modules\Tenancy\Data;

use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

final class TenantProvisioningResult
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly bool $r1Registry,
        public readonly bool $r2Storage,
        public readonly bool $r3Rbac,
        public readonly bool $r4Owner,
        public readonly bool $r5OwnerAuth,
        public readonly bool $r6Reachable,
        public readonly ?TenantUser $owner = null,
    ) {}

    public function isReady(): bool
    {
        return $this->r1Registry
            && $this->r2Storage
            && $this->r3Rbac
            && $this->r4Owner
            && $this->r5OwnerAuth
            && $this->r6Reachable;
    }

    public function withReachable(bool $reachable): self
    {
        return new self(
            tenant: $this->tenant,
            r1Registry: $this->r1Registry,
            r2Storage: $this->r2Storage,
            r3Rbac: $this->r3Rbac,
            r4Owner: $this->r4Owner,
            r5OwnerAuth: $this->r5OwnerAuth,
            r6Reachable: $reachable,
            owner: $this->owner,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'ready' => [
                'r1_registry' => $this->r1Registry,
                'r2_storage' => $this->r2Storage,
                'r3_rbac' => $this->r3Rbac,
                'r4_owner' => $this->r4Owner,
                'r5_owner_auth' => $this->r5OwnerAuth,
                'r6_reachable' => $this->r6Reachable,
                'is_ready' => $this->isReady(),
            ],
        ];
    }
}
