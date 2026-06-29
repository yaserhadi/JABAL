<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Tenancy\Models\AppSetting;
use Modules\Tenancy\Models\Tenant;

/**
 * Single access layer for tenant operational settings (tenant app_settings per DEC-0011).
 * Do not query AppSetting elsewhere.
 */
class TenantSettingsService
{
    public const REMOVAL_MODE_PERMANENT = 'permanent';

    public const REMOVAL_MODE_REVERSIBLE = 'reversible';

    protected function allowedAttributes(): array
    {
        return TenantAppSettingsBackfill::MIGRATED_ATTRIBUTES;
    }

    public function memberRemovalMode(Tenant $tenant): string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $row = $this->findAppSettingRow($tenant);
            $mode = $row?->member_removal_mode;

            if (in_array($mode, [self::REMOVAL_MODE_PERMANENT, self::REMOVAL_MODE_REVERSIBLE], true)) {
                return $mode;
            }

            return self::REMOVAL_MODE_PERMANENT;
        });
    }

    /**
     * Read-only branding for shell / shared Inertia (no tenant.settings.view required).
     *
     * @return array{display_name: string, branding_logo_url: ?string}
     */
    public function forShell(Tenant $tenant): array
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $row = $this->findAppSettingRow($tenant);

            return [
                'display_name' => $row?->display_name ?: $tenant->name,
                'branding_logo_url' => $row?->branding_logo_url,
            ];
        });
    }

    /**
     * Resolved values for admin form / API (defaults when row or fields null).
     *
     * @return array<string, mixed>
     */
    public function resolvedForTenant(Tenant $tenant): array
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $row = $this->findAppSettingRow($tenant);

            return [
                'display_name' => $row?->display_name,
                'timezone' => $row?->timezone ?? config('app.timezone'),
                'locale' => $row?->locale ?? config('app.locale'),
                'branding_logo_url' => $row?->branding_logo_url,
                'member_removal_mode' => $this->memberRemovalMode($tenant),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated attributes only
     */
    public function update(Tenant $tenant, array $data): AppSetting
    {
        return $this->withTenantContext($tenant, function () use ($tenant, $data) {
            $payload = Arr::only($data, $this->allowedAttributes());
            $existing = $this->findAppSettingRow($tenant);
            $oldValues = $existing ? $existing->getAttributes() : [];
            $oldRemovalMode = $this->memberRemovalMode($tenant);

            $record = AppSetting::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                array_merge(['tenant_id' => $tenant->id], $payload)
            );

            $record->refresh();

            $logger = app(AuditLoggerInterface::class);
            $tenantId = $tenant->getTenantKey();
            $base = [
                'tenant_id' => $tenantId,
                'auditable_type' => AppSetting::class,
                'auditable_id' => (string) $record->getKey(),
                'old_values' => $existing ? Arr::only($oldValues, array_merge(['id', 'tenant_id', 'created_at', 'updated_at'], $this->allowedAttributes())) : null,
                'new_values' => $record->only($this->allowedAttributes()),
            ];

            if (! $existing) {
                $logger->log('tenant_settings.created', $base);
            } else {
                $dirtyFields = [];
                foreach ($this->allowedAttributes() as $key) {
                    if (array_key_exists($key, $payload) && ($oldValues[$key] ?? null) != $record->getAttribute($key)) {
                        $dirtyFields[] = $key;
                    }
                }
                if ($dirtyFields !== []) {
                    $logger->log('tenant_settings.updated', $base);
                }
            }

            if (array_key_exists('member_removal_mode', $payload)) {
                $newMode = $this->memberRemovalMode($tenant);
                if ($newMode !== $oldRemovalMode) {
                    $logger->log('tenant_settings.member_removal_mode_changed', [
                        'tenant_id' => $tenantId,
                        'auditable_type' => AppSetting::class,
                        'auditable_id' => (string) $record->getKey(),
                        'old_values' => [
                            'old_mode' => $oldRemovalMode,
                            'reason' => null,
                        ],
                        'new_values' => [
                            'new_mode' => $newMode,
                            'changed_by' => Auth::id(),
                            'reason' => null,
                        ],
                    ]);
                }
            }

            return $record;
        });
    }

    protected function findAppSettingRow(Tenant $tenant): ?AppSetting
    {
        return AppSetting::query()->where('tenant_id', $tenant->id)->first();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withTenantContext(Tenant $tenant, callable $callback)
    {
        $wasInitialized = tenancy()->initialized;
        $previousTenant = $wasInitialized ? tenancy()->tenant : null;

        try {
            if (! $wasInitialized || tenancy()->tenant?->id !== $tenant->id) {
                tenancy()->initialize($tenant);
            }

            return $callback();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previousTenant && $previousTenant->id !== $tenant->id) {
                tenancy()->initialize($previousTenant);
            }
        }
    }
}
