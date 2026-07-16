<?php

namespace Modules\Tenancy\Support;

use Modules\Tenancy\Data\TenantProvisioningResult;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantOnboardingService;

/**
 * Derives truthful Platform provisioning presentation (BK-069 O2).
 * Only: completed | action_required. Never failed/in_progress without persisted evidence.
 */
final class TenantProvisioningPresenter
{
    public const COMPLETED = 'completed';

    public const ACTION_REQUIRED = 'action_required';

    public function __construct(
        private readonly TenantOnboardingService $onboarding,
    ) {}

    /**
     * From central Tenant (+ optional databaseConfig relation).
     *
     * @return array{status: string, detail: string, lifecycle_status: string}
     */
    public function fromTenant(Tenant $tenant): array
    {
        $tenant->loadMissing('databaseConfig');

        $lifecycle = (string) ($tenant->status ?: 'active');
        $storageReady = $this->onboarding->isStorageReady($tenant);

        if (! $storageReady) {
            $pending = $tenant->databaseConfig?->provisioning_status === 'pending'
                || $tenant->isolation_level === 'database';

            return [
                'status' => self::ACTION_REQUIRED,
                'detail' => $pending
                    ? 'Dedicated storage provisioning is incomplete. Run: php artisan tenant:provision-storage '.$tenant->id
                    : 'Tenant storage is not ready for the configured isolation mode.',
                'lifecycle_status' => $lifecycle,
            ];
        }

        // Shared (or dedicated active): registry row exists ⇒ R1 implied; R2 true.
        // Without live R3–R5 probes on list, treat storage-ready shared tenants as completed.
        // Detail may enrich via fromProvisioningResult after create / owner checks.
        if ($tenant->isolation_level === 'database') {
            $config = $tenant->databaseConfig;
            if ($config === null
                || $config->provisioning_status !== 'active'
                || $config->database_name === null) {
                return [
                    'status' => self::ACTION_REQUIRED,
                    'detail' => 'Dedicated database provisioning is incomplete. Run: php artisan tenant:provision-storage '.$tenant->id,
                    'lifecycle_status' => $lifecycle,
                ];
            }
        }

        return [
            'status' => self::COMPLETED,
            'detail' => 'Required storage readiness for this isolation mode is satisfied.',
            'lifecycle_status' => $lifecycle,
        ];
    }

    /**
     * @return array{status: string, detail: string, lifecycle_status: string, ready_flags: array<string, bool>}
     */
    public function fromProvisioningResult(TenantProvisioningResult $result): array
    {
        $tenant = $result->tenant->loadMissing('databaseConfig');
        $lifecycle = (string) ($tenant->status ?: 'active');
        $complete = $this->onboarding->isProvisioningComplete($result);

        $flags = [
            'r1_registry' => $result->r1Registry,
            'r2_storage' => $result->r2Storage,
            'r3_rbac' => $result->r3Rbac,
            'r4_owner' => $result->r4Owner,
            'r5_owner_auth' => $result->r5OwnerAuth,
        ];

        if ($complete) {
            return [
                'status' => self::COMPLETED,
                'detail' => 'Required R1–R5 conditions are satisfied for this isolation mode.',
                'lifecycle_status' => $lifecycle,
                'ready_flags' => $flags,
            ];
        }

        $missing = [];
        foreach ($flags as $key => $ok) {
            if (! $ok) {
                $missing[] = $key;
            }
        }

        $detail = 'Provisioning incomplete: '.implode(', ', $missing).'.';
        if (! $result->r2Storage) {
            $detail .= ' Storage pending — run: php artisan tenant:provision-storage '.$tenant->id;
        }

        return [
            'status' => self::ACTION_REQUIRED,
            'detail' => $detail,
            'lifecycle_status' => $lifecycle,
            'ready_flags' => $flags,
        ];
    }

    /**
     * Whether list filter by derived provisioning status is computable from central evidence only.
     * We support filtering action_required when dedicated config is pending or missing database_name.
     * Filtering "completed" uses: shared isolation OR (database + active config with name).
     */
    public function supportsCentralListFilter(): bool
    {
        return true;
    }
}
