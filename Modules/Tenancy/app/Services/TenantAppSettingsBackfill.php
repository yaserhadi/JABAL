<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Models\AppSetting;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetting;

/**
 * BK-028: Idempotent backfill from central tenant_settings to tenant app_settings.
 */
class TenantAppSettingsBackfill
{
    /** @var list<string> */
    public const MIGRATED_ATTRIBUTES = [
        'display_name',
        'timezone',
        'locale',
        'branding_logo_url',
        'member_removal_mode',
    ];

    /**
     * @return array{tenants: int, backfilled: int, skipped: int, errors: list<string>}
     */
    public function backfillAll(): array
    {
        $stats = [
            'tenants' => 0,
            'backfilled' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$stats) {
            $stats['tenants']++;
            try {
                $result = $this->backfillTenant($tenant);
                if ($result === 'backfilled') {
                    $stats['backfilled']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = $tenant->id.': '.$e->getMessage();
            }
        });

        return $stats;
    }

    /**
     * @return 'backfilled'|'skipped_no_central_row'|'skipped_already_parity'
     */
    public function backfillTenant(Tenant $tenant): string
    {
        $central = TenantSetting::query()->where('tenant_id', $tenant->id)->first();
        if (! $central) {
            return 'skipped_no_central_row';
        }

        $payload = $this->payloadFromCentral($central);
        $wasInitialized = tenancy()->initialized;
        $previousTenant = $wasInitialized ? tenancy()->tenant : null;

        try {
            if (! $wasInitialized || tenancy()->tenant?->id !== $tenant->id) {
                tenancy()->initialize($tenant);
            }

            $existing = AppSetting::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($existing && $this->fieldsMatch($existing, $payload)) {
                return 'skipped_already_parity';
            }

            AppSetting::query()->withoutGlobalScope('tenant')->updateOrCreate(
                ['tenant_id' => $tenant->id],
                array_merge(['tenant_id' => $tenant->id], $payload)
            );

            return 'backfilled';
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previousTenant && $previousTenant->id !== $tenant->id) {
                tenancy()->initialize($previousTenant);
            }
        }
    }

    /**
     * @return array{ok: bool, mismatches: list<string>}
     */
    public function verifyTenantParity(Tenant $tenant): array
    {
        $central = TenantSetting::query()->where('tenant_id', $tenant->id)->first();
        if (! $central) {
            return ['ok' => true, 'mismatches' => []];
        }

        $wasInitialized = tenancy()->initialized;
        $previousTenant = $wasInitialized ? tenancy()->tenant : null;

        try {
            if (! $wasInitialized || tenancy()->tenant?->id !== $tenant->id) {
                tenancy()->initialize($tenant);
            }

            $tenantRow = AppSetting::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->first();

            if (! $tenantRow) {
                return ['ok' => false, 'mismatches' => ['missing tenant app_settings row']];
            }

            $payload = $this->payloadFromCentral($central);
            $mismatches = [];
            foreach (self::MIGRATED_ATTRIBUTES as $key) {
                if (($tenantRow->getAttribute($key) ?? null) != ($payload[$key] ?? null)) {
                    $mismatches[] = $key;
                }
            }

            return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previousTenant && $previousTenant->id !== $tenant->id) {
                tenancy()->initialize($previousTenant);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFromCentral(TenantSetting $central): array
    {
        $payload = [];
        foreach (self::MIGRATED_ATTRIBUTES as $key) {
            $payload[$key] = $central->getAttribute($key);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function fieldsMatch(AppSetting $existing, array $payload): bool
    {
        foreach (self::MIGRATED_ATTRIBUTES as $key) {
            if (($existing->getAttribute($key) ?? null) != ($payload[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
