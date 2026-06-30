<?php

namespace App\Support\Tenancy;

use App\Support\Contracts\Tenancy\TenantStorageResolver;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * Cross-connection tenant auth lookups before tenancy is initialized (login, session deferral).
 */
class TenantAuthLookup
{
    public function __construct(
        private readonly TenantStorageResolver $resolver,
        private readonly TenantConnectionRegistry $registry,
    ) {}

    public function findUserByEmail(string $email): ?TenantUser
    {
        $user = $this->queryUserOnConnection($this->sharedConnection())
            ->where('email', $email)
            ->first();

        if ($user instanceof TenantUser) {
            return $user;
        }

        if (config('tenancy_storage.mode') !== 'database_per_tenant') {
            return null;
        }

        foreach ($this->dedicatedTenants() as $tenant) {
            $this->registry->register($tenant);
            $connection = $this->resolver->connectionFor($tenant);

            $user = $this->queryUserOnConnection($connection)
                ->where('email', $email)
                ->first();

            if ($user instanceof TenantUser) {
                return $user;
            }
        }

        return null;
    }

    public function findUserById(string $id): ?TenantUser
    {
        $user = $this->queryUserOnConnection($this->sharedConnection())
            ->where($this->authIdentifierColumn(), $id)
            ->first();

        if ($user instanceof TenantUser) {
            return $user;
        }

        if (config('tenancy_storage.mode') !== 'database_per_tenant') {
            return null;
        }

        foreach ($this->dedicatedTenants() as $tenant) {
            $this->registry->register($tenant);
            $connection = $this->resolver->connectionFor($tenant);

            $user = $this->queryUserOnConnection($connection)
                ->where($this->authIdentifierColumn(), $id)
                ->first();

            if ($user instanceof TenantUser) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<TenantUser>
     */
    private function queryUserOnConnection(string $connection): \Illuminate\Database\Eloquent\Builder
    {
        return TenantUser::on($connection)->withoutGlobalScope('tenant');
    }

    private function authIdentifierColumn(): string
    {
        $model = new TenantUser;

        return $model->getAuthIdentifierName();
    }

    private function sharedConnection(): string
    {
        return (string) config('tenancy_storage.shared_connection', 'tenant');
    }

    /**
     * @return iterable<int, Tenant>
     */
    private function dedicatedTenants(): iterable
    {
        return Tenant::query()
            ->where('status', 'active')
            ->where('isolation_level', 'database')
            ->whereHas('databaseConfig', function ($query) {
                $query->where('provisioning_status', 'active')
                    ->where('isolation_level', 'database');
            })
            ->with('databaseConfig')
            ->cursor();
    }
}
