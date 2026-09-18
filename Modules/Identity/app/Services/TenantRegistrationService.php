<?php

namespace Modules\Identity\Services;

use App\Support\Tenancy\TenantDatabaseProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Modules\Tenancy\Services\TenantHandleLifecycleService;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use Modules\Tenancy\Support\TenantHandleValidator;

class TenantRegistrationService
{
    public function __construct(
        private readonly TenantHandleValidator $handles,
    ) {}

    /**
     * Self-service registration: account + Tenant with caller-selected Web address (handle).
     *
     * @throws ValidationException
     */
    public function registerTenantUser(
        string $name,
        string $email,
        string $password,
        string $webAddress,
    ): TenantUser {
        $isolationLevel = $this->resolveRegistrationIsolationLevel();

        try {
            $handle = $this->handles->assertValidForCreate($webAddress);

            return DB::connection('central')->transaction(function () use (
                $name,
                $email,
                $password,
                $handle,
                $isolationLevel
            ) {
                // Re-check inside the transaction boundary for race safety.
                $handle = $this->handles->assertValidForCreate($handle);

                $tenant = Tenant::create([
                    'name' => $name."'s Organization",
                    'slug' => $handle,
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

                app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant->fresh());
                app(TenantHandleLifecycleService::class)->recordInitialAssignment($tenant->fresh());

                tenancy()->initialize($tenant->fresh(['databaseConfig']));

                try {
                    $tenantUser = TenantUser::create([
                        'tenant_id' => $tenant->id,
                        'name' => $name,
                        'email' => $email,
                        'password' => $password,
                    ]);

                    $tenant->update([
                        'created_by' => $tenantUser->id,
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

                    return TenantUser::withoutGlobalScope('tenant')->findOrFail($tenantUser->getKey());
                } finally {
                    tenancy()->end();
                }
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'web_address' => ['This web address is not available.'],
                ]);
            }

            throw $e;
        } catch (ValidationException $e) {
            $messages = $e->errors();
            if (isset($messages['handle']) || isset($messages['slug'])) {
                $msg = $messages['handle'][0] ?? $messages['slug'][0] ?? 'This web address is not available.';
                throw ValidationException::withMessages([
                    'web_address' => [$this->customerFacingAvailabilityMessage($msg)],
                ]);
            }

            throw $e;
        }
    }

    private function customerFacingAvailabilityMessage(string $internal): string
    {
        $lower = strtolower($internal);
        if (str_contains($lower, 'reserved')) {
            return 'This web address is reserved.';
        }
        if (str_contains($lower, 'not available') || str_contains($lower, 'taken')) {
            return 'This web address is already taken.';
        }
        if (str_contains($lower, 'required') || str_contains($lower, 'must') || str_contains($lower, 'may only')) {
            return 'This web address is invalid.';
        }

        return 'This web address is not available.';
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505' || $driverCode === 1062 || $driverCode === 19;
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
