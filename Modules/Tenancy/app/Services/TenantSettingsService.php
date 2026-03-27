<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Arr;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetting;

/**
 * Phase 3D: Single access layer for tenant_settings (central). Do not query TenantSetting elsewhere.
 */
class TenantSettingsService
{
    protected function allowedAttributes(): array
    {
        return [
            'display_name',
            'timezone',
            'locale',
            'branding_logo_url',
        ];
    }

    /**
     * Read-only branding for shell / shared Inertia (no tenant.settings.view required).
     *
     * @return array{display_name: string, branding_logo_url: ?string}
     */
    public function forShell(Tenant $tenant): array
    {
        $row = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

        return [
            'display_name' => $row?->display_name ?: $tenant->name,
            'branding_logo_url' => $row?->branding_logo_url,
        ];
    }

    /**
     * Resolved values for admin form / API (defaults when row or fields null).
     *
     * @return array<string, mixed>
     */
    public function resolvedForTenant(Tenant $tenant): array
    {
        $row = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

        return [
            'display_name' => $row?->display_name,
            'timezone' => $row?->timezone ?? config('app.timezone'),
            'locale' => $row?->locale ?? config('app.locale'),
            'branding_logo_url' => $row?->branding_logo_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated attributes only
     */
    public function update(Tenant $tenant, array $data): TenantSetting
    {
        $payload = Arr::only($data, $this->allowedAttributes());
        $existing = TenantSetting::query()->where('tenant_id', $tenant->id)->first();
        $oldValues = $existing ? $existing->getAttributes() : [];

        $record = TenantSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            array_merge(['tenant_id' => $tenant->id], $payload)
        );

        $record->refresh();

        $logger = app(AuditLoggerInterface::class);
        $tenantId = $tenant->getTenantKey();
        $base = [
            'tenant_id' => $tenantId,
            'auditable_type' => TenantSetting::class,
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

        return $record;
    }
}
