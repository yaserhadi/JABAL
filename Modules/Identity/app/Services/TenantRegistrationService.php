<?php

namespace Modules\Identity\Services;

use App\Models\User;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantRbacProvisioner;

class TenantRegistrationService
{
    public function registerTenantUser(string $name, string $email, string $password): User
    {
        $isolationLevel = $this->resolveRegistrationIsolationLevel();

        $tenant = Tenant::create([
            'name' => $name."'s Workspace",
            'slug' => 'pending-'.uniqid(),
            'isolation_level' => $isolationLevel,
            'status' => 'active',
        ]);

        if ($isolationLevel === 'database') {
            TenantDatabaseConfig::create([
                'tenant_id' => $tenant->id,
                'isolation_level' => 'database',
                'provisioning_status' => 'pending',
            ]);

            if (config('tenancy_storage.db_creation_mode') === 'automatic') {
                app(TenantDatabaseProvisioner::class)->provision($tenant->fresh(['databaseConfig']));
            } else {
                throw new \RuntimeException(
                    'Dedicated database registration requires TENANCY_DB_CREATION_MODE=automatic or org provisioning via tenant:onboard-organization.'
                );
            }
        }

        tenancy()->initialize($tenant->fresh(['databaseConfig']));

        try {
            $tenantUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant->update([
                'created_by' => $tenantUser->id,
                'slug' => 'ws-'.strtolower(substr(str_replace('-', '', $tenantUser->id), 0, 12)),
            ]);

            Membership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $tenantUser->id,
                'membership_type' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $rbac = app(TenantRbacProvisioner::class);
            $rbac->ensureGlobalPermissions();
            $rbac->ensureRolesForTenant($tenant);
            $rbac->assignTenantAdminRole($tenantUser, $tenant);

            return User::withoutGlobalScope('tenant')->findOrFail($tenantUser->getKey());
        } finally {
            tenancy()->end();
        }
    }

    private function resolveRegistrationIsolationLevel(): string
    {
        if (config('tenancy_storage.mode') !== 'database_per_tenant') {
            return 'shared';
        }

        $default = (string) config('tenancy_storage.default_isolation_level', 'shared');

        return $default === 'database' ? 'database' : 'shared';
    }
}
