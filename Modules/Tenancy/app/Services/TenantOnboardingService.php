<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Billing\TenantSubscriptionProvisioner;
use App\Support\Contracts\Tenancy\TenantStorageResolver;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Data\TenantProvisioningResult;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;

/**
 * Single orchestrator for organization tenant environment provisioning (BK-005).
 */
class TenantOnboardingService
{
    public function __construct(
        private readonly TenantStorageResolver $storageResolver,
        private readonly TenantDatabaseProvisioner $databaseProvisioner,
        private readonly TenantRbacProvisioner $rbacProvisioner,
        private readonly TenantSubscriptionProvisioner $subscriptionProvisioner,
    ) {}

    public function onboardOrganizationTenant(TenantOnboardingInput $input): TenantProvisioningResult
    {
        $this->assertValidOnboardingInput($input);

        $tenant = $this->satisfyR1Registry($input);

        $this->subscriptionProvisioner->ensureDefaultSubscription($tenant->id);

        $r2 = $this->satisfyR2Storage($tenant);

        $r3 = $this->satisfyR3Rbac($tenant);
        $owner = $this->satisfyR4Owner($tenant, $input);
        $r4 = true;
        $r5 = $this->satisfyR5OwnerAuth($owner, $tenant);

        return new TenantProvisioningResult(
            tenant: $tenant->fresh(['databaseConfig']),
            r1Registry: true,
            r2Storage: $r2,
            r3Rbac: $r3,
            r4Owner: $r4,
            r5OwnerAuth: $r5,
            r6Reachable: false,
            owner: $owner,
        );
    }

    /**
     * Complete R2 for manual provisioning strategy, then R3–R5 if not yet satisfied.
     */
    public function completeStorageProvisioning(Tenant $tenant): TenantProvisioningResult
    {
        $tenant->loadMissing('databaseConfig');

        $this->databaseProvisioner->provision($tenant);
        $tenant->refresh(['databaseConfig']);

        $r2 = $this->isStorageReady($tenant);
        $r3 = false;
        $r4 = false;
        $r5 = false;
        $owner = null;

        if ($r2) {
            $r3 = $this->satisfyR3Rbac($tenant);
            $owner = $this->findExistingOwner($tenant);
            $r4 = $owner !== null;
            if ($r4) {
                $r5 = $this->satisfyR5OwnerAuth($owner, $tenant);
            }
        }

        return new TenantProvisioningResult(
            tenant: $tenant->fresh(['databaseConfig']),
            r1Registry: true,
            r2Storage: $r2,
            r3Rbac: $r3,
            r4Owner: $r4,
            r5OwnerAuth: $r5,
            r6Reachable: false,
            owner: $owner,
        );
    }

    public function satisfyR1Registry(TenantOnboardingInput $input): Tenant
    {
        $slug = $input->slug ?? Str::slug($input->organizationName).'-'.Str::lower(Str::random(6));

        return Tenant::query()->create([
            'name' => $input->organizationName,
            'slug' => $slug,
            'isolation_level' => $input->isolationLevel,
            'status' => 'active',
        ]);
    }

    public function satisfyR2Storage(Tenant $tenant): bool
    {
        if ($this->needsDedicatedConfigRow($tenant)) {
            TenantDatabaseConfig::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'isolation_level' => 'database',
                    'provisioning_status' => 'pending',
                ]
            );
            $tenant->load('databaseConfig');

            if ($this->shouldProvisionAutomatically()) {
                $this->databaseProvisioner->provision($tenant);
                $tenant->refresh(['databaseConfig']);
            }
        }

        return $this->isStorageReady($tenant);
    }

    public function satisfyR3Rbac(Tenant $tenant): bool
    {
        return (bool) $this->runWithTenantWebGuard(function () use ($tenant) {
            tenancy()->initialize($tenant);

            try {
                $this->rbacProvisioner->ensureGlobalPermissions();
                $this->rbacProvisioner->ensureRolesForTenant($tenant);

                return true;
            } finally {
                tenancy()->end();
            }
        });
    }

    public function satisfyR4Owner(Tenant $tenant, TenantOnboardingInput $input): TenantUser
    {
        return $this->runWithTenantWebGuard(function () use ($tenant, $input) {
            tenancy()->initialize($tenant);

            try {
                $owner = TenantUser::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $input->ownerName,
                    'email' => $input->ownerEmail,
                    'password' => $input->ownerPassword,
                ]);

                Membership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $owner->id,
                    'membership_type' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

                $tenant->update(['created_by' => $owner->id]);

                return TenantUser::withoutGlobalScope('tenant')->findOrFail($owner->id);
            } finally {
                tenancy()->end();
            }
        });
    }

    public function satisfyR5OwnerAuth(TenantUser $owner, Tenant $tenant): bool
    {
        return (bool) $this->runWithTenantWebGuard(function () use ($owner, $tenant) {
            tenancy()->initialize($tenant);

            try {
                $this->rbacProvisioner->assignTenantAdminRole($owner, $tenant);

                $found = TenantUser::findForLogin($owner->email);

                if (! $found) {
                    return false;
                }

                $hasMembership = Membership::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $owner->id)
                    ->where('status', 'active')
                    ->exists();

                if (! $hasMembership) {
                    return false;
                }

                app(\Spatie\Permission\PermissionRegistrar::class)
                    ->setPermissionsTeamId($tenant->getTenantKey());

                try {
                    return $found->hasRole('tenant-admin');
                } finally {
                    app(\Spatie\Permission\PermissionRegistrar::class)
                        ->setPermissionsTeamId(null);
                }
            } finally {
                tenancy()->end();
            }
        });
    }

    public function isStorageReady(Tenant $tenant): bool
    {
        $tenant->loadMissing('databaseConfig');

        if ($this->needsDedicatedConfigRow($tenant)) {
            $config = $tenant->databaseConfig;

            return $config instanceof TenantDatabaseConfig
                && $config->provisioning_status === 'active'
                && $config->database_name !== null;
        }

        return true;
    }

    public function isProvisioningComplete(TenantProvisioningResult $result): bool
    {
        return $result->r1Registry
            && $result->r2Storage
            && $result->r3Rbac
            && $result->r4Owner
            && $result->r5OwnerAuth;
    }

    protected function runWithTenantWebGuard(callable $callback): mixed
    {
        $previousGuard = config('auth.defaults.guard');
        config(['auth.defaults.guard' => 'web']);

        try {
            return $callback();
        } finally {
            config(['auth.defaults.guard' => $previousGuard]);
        }
    }

    protected function needsDedicatedConfigRow(Tenant $tenant): bool
    {
        if ($tenant->isolation_level !== 'database') {
            return false;
        }

        return $this->storageResolver->mode() === 'database_per_tenant'
            && (bool) config('tenancy_storage.allow_database_per_tenant', true);
    }

    protected function shouldProvisionAutomatically(): bool
    {
        return (string) config('tenancy_storage.db_creation_mode', 'manual') === 'automatic';
    }

    protected function findExistingOwner(Tenant $tenant): ?TenantUser
    {
        tenancy()->initialize($tenant);

        try {
            $membership = Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('membership_type', 'owner')
                ->where('status', 'active')
                ->first();

            if (! $membership) {
                return null;
            }

            return TenantUser::withoutGlobalScope('tenant')->find($membership->user_id);
        } finally {
            tenancy()->end();
        }
    }

    protected function assertValidOnboardingInput(TenantOnboardingInput $input): void
    {
        if (! in_array($input->isolationLevel, ['shared', 'database'], true)) {
            throw new InvalidArgumentException(
                'Organization onboarding supports isolation_level shared or database only; '
                .$input->isolationLevel.' is not supported (schema_per_tenant is BK-032+).'
            );
        }

        if (TenantUser::withoutGlobalScope('tenant')->where('email', $input->ownerEmail)->exists()) {
            throw new InvalidArgumentException(
                'Owner email ['.$input->ownerEmail.'] is already registered to a tenant user.'
            );
        }
    }
}
