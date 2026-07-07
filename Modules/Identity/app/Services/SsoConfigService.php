<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008: Tenant SSO configuration (tenant layer). Secrets are write-only at API boundary.
 */
class SsoConfigService
{
    /** @var list<string> */
    protected const PUBLIC_FIELDS = [
        'enabled',
        'disabled_by_entitlement',
        'provider_label',
        'issuer_url',
        'client_id',
        'redirect_uri',
        'scopes',
    ];

    public function __construct(
        protected SecurityFeatureGate $featureGate,
    ) {}

    public function getForTenant(Tenant $tenant): array
    {
        $this->assertOrganizationTenant($tenant);

        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findRow($tenant);

            if (! $record) {
                return $this->emptyPublicPayload();
            }

            return $this->toPublicArray($record);
        });
    }

    public function update(Tenant $tenant, array $data): TenantSsoConfig
    {
        $this->assertOrganizationTenant($tenant);

        return $this->withTenantContext($tenant, function () use ($tenant, $data) {
            $existing = $this->findRow($tenant);
            $payload = Arr::only($data, self::PUBLIC_FIELDS);

            if (array_key_exists('enabled', $payload) && $payload['enabled']) {
                if ($existing?->disabled_by_entitlement) {
                    abort(403, 'SSO cannot be enabled while disabled by entitlement.');
                }

                $this->assertSsoEntitlement($tenant);
            }

            if (array_key_exists('client_secret', $data) && is_string($data['client_secret']) && $data['client_secret'] !== '') {
                $payload['client_secret_encrypted'] = Crypt::encryptString($data['client_secret']);
            }

            unset($payload['client_secret']);

            $oldValues = $existing ? $this->auditSnapshot($existing) : null;

            if ($existing) {
                $existing->update($payload);
                $record = $existing->fresh();
            } else {
                $record = TenantSsoConfig::query()->create(array_merge([
                    'tenant_id' => $tenant->id,
                    'enabled' => false,
                    'disabled_by_entitlement' => false,
                    'scopes' => config('identity.sso.default_scopes', ['openid', 'profile', 'email']),
                ], $payload));
            }

            $this->logConfigChange($tenant, $record, $existing !== null, $oldValues);

            return $record;
        });
    }

    public function isOperationalForTenant(Tenant $tenant): bool
    {
        if ($tenant->type !== 'organization') {
            return false;
        }

        if (! $this->featureGate->isSsoAvailable($tenant)) {
            return false;
        }

        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findRow($tenant);

            return (bool) ($record?->enabled)
                && ! (bool) ($record?->disabled_by_entitlement)
                && filled($record?->issuer_url)
                && filled($record?->client_id)
                && filled($record?->client_secret_encrypted);
        });
    }

    /**
     * BK-008: Disable SSO when plan loses sso_available — preserve config/secrets; no session revoke.
     */
    public function disableForEntitlementLoss(Tenant $tenant): bool
    {
        if ($tenant->type !== 'organization') {
            return false;
        }

        return $this->withTenantContext($tenant, function () use ($tenant) {
            $existing = $this->findRow($tenant);

            if (! $existing) {
                return false;
            }

            if (! $existing->enabled && $existing->disabled_by_entitlement) {
                return false;
            }

            $oldValues = $this->auditSnapshot($existing);
            $existing->update([
                'enabled' => false,
                'disabled_by_entitlement' => true,
            ]);

            $this->logConfigChange($tenant, $existing->fresh(), true, $oldValues);

            return true;
        });
    }

    /**
     * BK-008: Clear entitlement-loss flag when sso_available returns — does not auto-enable SSO.
     */
    public function clearEntitlementDisableFlag(Tenant $tenant): bool
    {
        if ($tenant->type !== 'organization' || ! $this->featureGate->isSsoAvailable($tenant)) {
            return false;
        }

        return $this->withTenantContext($tenant, function () use ($tenant) {
            $existing = $this->findRow($tenant);

            if (! $existing || ! $existing->disabled_by_entitlement) {
                return false;
            }

            $oldValues = $this->auditSnapshot($existing);
            $existing->update(['disabled_by_entitlement' => false]);
            $this->logConfigChange($tenant, $existing->fresh(), true, $oldValues);

            return true;
        });
    }

    /**
     * Internal use by SsoAuthService only — never expose via GET/Inertia.
     */
    public function getDecryptedClientSecret(Tenant $tenant): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $encrypted = $this->findRow($tenant)?->client_secret_encrypted;

            if (! is_string($encrypted) || $encrypted === '') {
                return null;
            }

            return Crypt::decryptString($encrypted);
        });
    }

    public function getConfiguredIssuer(Tenant $tenant): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $issuer = $this->findRow($tenant)?->issuer_url;

            return is_string($issuer) && $issuer !== '' ? $issuer : null;
        });
    }

    protected function findRow(Tenant $tenant): ?TenantSsoConfig
    {
        return TenantSsoConfig::query()->where('tenant_id', $tenant->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function toPublicArray(TenantSsoConfig $record): array
    {
        return [
            'enabled' => $record->enabled,
            'disabled_by_entitlement' => $record->disabled_by_entitlement,
            'provider_label' => $record->provider_label,
            'issuer_url' => $record->issuer_url,
            'client_id' => $record->client_id,
            'redirect_uri' => $record->redirect_uri,
            'scopes' => $record->scopes ?? config('identity.sso.default_scopes', ['openid', 'profile', 'email']),
            'has_client_secret' => filled($record->getAttributes()['client_secret_encrypted'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPublicPayload(): array
    {
        return [
            'enabled' => false,
            'disabled_by_entitlement' => false,
            'provider_label' => null,
            'issuer_url' => null,
            'client_id' => null,
            'redirect_uri' => null,
            'scopes' => config('identity.sso.default_scopes', ['openid', 'profile', 'email']),
            'has_client_secret' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditSnapshot(TenantSsoConfig $record): array
    {
        return [
            'enabled' => $record->enabled,
            'disabled_by_entitlement' => $record->disabled_by_entitlement,
            'provider_label' => $record->provider_label,
            'issuer_url' => $record->issuer_url,
            'client_id' => $record->client_id,
            'redirect_uri' => $record->redirect_uri,
            'scopes' => $record->scopes,
            'has_client_secret' => filled($record->getAttributes()['client_secret_encrypted'] ?? null),
        ];
    }

    protected function logConfigChange(
        Tenant $tenant,
        TenantSsoConfig $record,
        bool $wasUpdate,
        ?array $oldValues,
    ): void {
        $logger = app(AuditLoggerInterface::class);
        $base = [
            'tenant_id' => $tenant->getTenantKey(),
            'auditable_type' => TenantSsoConfig::class,
            'auditable_id' => (string) $record->getKey(),
            'old_values' => $oldValues,
            'new_values' => $this->auditSnapshot($record),
            'changed_by' => Auth::id(),
        ];

        $logger->log($wasUpdate ? 'sso.config.updated' : 'sso.config.created', $base);
    }

    protected function assertOrganizationTenant(Tenant $tenant): void
    {
        if ($tenant->type !== 'organization') {
            abort(403, 'SSO is available for organization tenants only.');
        }
    }

    protected function assertSsoEntitlement(Tenant $tenant): void
    {
        if (! $this->featureGate->isSsoAvailable($tenant)) {
            abort(403, 'SSO is not available for this tenant plan.');
        }
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
