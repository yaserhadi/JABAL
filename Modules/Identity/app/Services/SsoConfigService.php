<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\SsoPlatformControl;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008 / BK-082: Tenant SSO configuration (tenant layer).
 *
 * Material IdP fields are versioned (immutable after activation). Operational
 * flags remain on the parent row. Secrets are write-only at the API boundary.
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
        'jwks_uri',
        'logout_token_signing_algs',
    ];

    /** @var list<string> */
    protected const MATERIAL_FIELDS = [
        'provider_label',
        'issuer_url',
        'client_id',
        'client_secret_encrypted',
        'redirect_uri',
        'scopes',
        'jwks_uri',
        'logout_token_signing_algs',
    ];

    /** @var list<string> */
    protected const FLAG_FIELDS = [
        'enabled',
        'disabled_by_entitlement',
    ];

    public function __construct(
        protected SecurityFeatureGate $featureGate,
        protected \Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService $credentialAccess,
    ) {}

    public function getForTenant(Tenant $tenant): array
    {
        $this->assertActiveTenant($tenant);

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
        $this->assertActiveTenant($tenant);

        return $this->withTenantContext($tenant, function () use ($tenant, $data) {
            return DB::connection('tenant')->transaction(function () use ($tenant, $data) {
                $existing = $this->findRow($tenant);
                $payload = Arr::only($data, self::PUBLIC_FIELDS);

                if (array_key_exists('enabled', $payload) && $payload['enabled']) {
                    if ($existing?->disabled_by_entitlement) {
                        abort(403, 'SSO cannot be enabled while disabled by entitlement.');
                    }

                    $this->assertSsoEntitlement($tenant);
                }

                if (array_key_exists('client_secret', $data) && is_string($data['client_secret']) && $data['client_secret'] !== '') {
                    // Active reference path: no new operational ciphertext writes (BK-098).
                    if ($existing?->active_version_id) {
                        $active = TenantSsoConfigVersion::query()
                            ->where('tenant_id', $tenant->id)
                            ->whereKey($existing->active_version_id)
                            ->first();
                        if ($active
                            && $this->credentialAccess->credentialSource($active)
                                === \Modules\Identity\Support\Sso\Credentials\IdpCredentialResolver::SOURCE_REFERENCE) {
                            abort(422, 'Active reference credential cannot accept plaintext client_secret writes; re-provision via sealed management.');
                        }
                    }

                    $payload['client_secret_encrypted'] = Crypt::encryptString($data['client_secret']);
                }

                unset($payload['client_secret']);

                $oldValues = $existing ? $this->auditSnapshot($existing) : null;
                $flagPayload = Arr::only($payload, self::FLAG_FIELDS);
                $materialPayload = Arr::only($payload, self::MATERIAL_FIELDS);
                $secretProvided = array_key_exists('client_secret_encrypted', $materialPayload);

                if (! $existing) {
                    $record = TenantSsoConfig::query()->create(array_merge([
                        'tenant_id' => $tenant->id,
                        'enabled' => false,
                        'disabled_by_entitlement' => false,
                        'rollout_state' => TenantSsoConfig::ROLLOUT_ENABLED,
                        'scopes' => config('identity.sso.default_scopes', ['openid', 'profile', 'email']),
                    ], $payload));

                    $version = $this->createActiveVersion($record, $this->materialSnapshotFromConfig($record));
                    $record->forceFill(['active_version_id' => $version->id])->save();
                    $record = $record->fresh();

                    $this->logConfigChange($tenant, $record, false, $oldValues);

                    return $record;
                }

                $materialChanged = $this->materialPayloadDiffers($existing, $materialPayload, $secretProvided);

                if ($materialChanged) {
                    $nextMaterial = $this->mergeMaterial($existing, $materialPayload, $secretProvided);
                    $this->supersedeActiveVersion($existing);
                    $version = $this->createActiveVersion($existing, $nextMaterial);
                    // Parent row holds operational mirror of MATERIAL_FIELDS only —
                    // credential_* authority stays on the version (BK-098).
                    $existing->forceFill(array_merge(
                        Arr::only($nextMaterial, self::MATERIAL_FIELDS),
                        [
                            'active_version_id' => $version->id,
                        ],
                        $flagPayload,
                    ))->save();
                } elseif ($flagPayload !== []) {
                    $existing->forceFill($flagPayload)->save();
                }

                $record = $existing->fresh();
                $this->logConfigChange($tenant, $record, true, $oldValues);

                return $record;
            });
        });
    }

    /**
     * Bindable IdP configuration version id for Authentication Transactions (D15).
     */
    public function getActiveVersionId(Tenant $tenant): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $config = $this->findRow($tenant);
            $id = $config?->active_version_id;
            if (! is_string($id) || $id === '') {
                return null;
            }

            $version = TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($id)
                ->first();

            if (! $version instanceof TenantSsoConfigVersion) {
                return null;
            }

            if ($version->mayServeNewProductionLogin() || $version->isTestOnly()) {
                return $id;
            }

            return null;
        });
    }

    public function getActiveVersion(Tenant $tenant): ?TenantSsoConfigVersion
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $config = $this->findRow($tenant);

            if (! $config?->active_version_id) {
                return null;
            }

            return TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($config->active_version_id)
                ->first();
        });
    }

    /**
     * Resolve a version for Host transaction binding. Fail closed on tenant mismatch.
     */
    public function findVersionForTenant(Tenant $tenant, string $versionId): ?TenantSsoConfigVersion
    {
        return $this->withTenantContext($tenant, function () use ($tenant, $versionId) {
            return TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($versionId)
                ->first();
        });
    }

    public function isOperationalForTenant(Tenant $tenant): bool
    {
        if ($tenant->status !== 'active') {
            return false;
        }

        if (! $this->featureGate->isSsoAvailable($tenant)) {
            return false;
        }

        if (SsoPlatformControl::current()->disable_enterprise_sso) {
            return false;
        }

        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findRow($tenant);
            if (! $record) {
                return false;
            }

            $rollout = (string) ($record->rollout_state ?: TenantSsoConfig::ROLLOUT_ENABLED);
            if (in_array($rollout, [
                TenantSsoConfig::ROLLOUT_DISABLED,
                TenantSsoConfig::ROLLOUT_SECURITY_DISABLED,
                TenantSsoConfig::ROLLOUT_PAUSED,
            ], true)) {
                return false;
            }

            if (! (bool) $record->enabled
                || (bool) $record->disabled_by_entitlement
                || ! filled($record->issuer_url)
                || ! filled($record->client_id)
                || ! filled($record->active_version_id)) {
                return false;
            }

            $version = TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($record->active_version_id)
                ->first();

            if (! $version instanceof TenantSsoConfigVersion) {
                return false;
            }

            if (! $this->credentialAccess->versionHasUsableCredential($version)) {
                return false;
            }

            // Test-only / pilot remain "configured" but are not generally operational.
            if ($version->isTestOnly() || $rollout === TenantSsoConfig::ROLLOUT_TEST_ONLY) {
                return false;
            }

            return $version->mayServeNewProductionLogin();
        });
    }

    /**
     * BK-008: Disable SSO when plan loses sso_available — preserve config/secrets; no session revoke.
     * Does not create a new IdP configuration version (operational flag only).
     */
    public function disableForEntitlementLoss(Tenant $tenant): bool
    {
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
        if (! $this->featureGate->isSsoAvailable($tenant)) {
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
     * Resolve IdP client secret via active version (BK-098 cutover — never parent ciphertext).
     * Internal use by SsoAuthService only — never expose via GET/Inertia.
     */
    public function getDecryptedClientSecret(Tenant $tenant): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $row = $this->findRow($tenant);
            if (! $row?->active_version_id) {
                return null;
            }

            $version = TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($row->active_version_id)
                ->first();

            if (! $version instanceof TenantSsoConfigVersion) {
                return null;
            }

            try {
                return $this->credentialAccess->resolveClientSecret(
                    $tenant,
                    $version,
                    \Modules\Identity\Support\Sso\Credentials\CredentialPurpose::OidcClientAuth,
                    'client_secret_post',
                );
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Resolve IdP client secret for a bound IdP configuration version (BK-098 cutover).
     */
    public function getDecryptedClientSecretForVersion(Tenant $tenant, TenantSsoConfigVersion $version): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant, $version) {
            try {
                return $this->credentialAccess->resolveClientSecret(
                    $tenant,
                    $version,
                    \Modules\Identity\Support\Sso\Credentials\CredentialPurpose::OidcClientAuth,
                    'client_secret_post',
                );
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * HS256 Back-Channel Logout credential (purpose-aware; no JWKS path).
     */
    public function getHs256LogoutSecretForVersion(Tenant $tenant, TenantSsoConfigVersion $version): ?string
    {
        return $this->withTenantContext($tenant, function () use ($tenant, $version) {
            try {
                return $this->credentialAccess->resolveClientSecret(
                    $tenant,
                    $version,
                    \Modules\Identity\Support\Sso\Credentials\CredentialPurpose::BackchannelLogoutHs256,
                    'client_secret_post',
                );
            } catch (\Throwable) {
                return null;
            }
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
     * @param  array<string, mixed>  $material
     */
    protected function createActiveVersion(TenantSsoConfig $config, array $material): TenantSsoConfigVersion
    {
        $nextNumber = (int) TenantSsoConfigVersion::query()
            ->where('config_id', $config->id)
            ->max('version_number');

        return TenantSsoConfigVersion::query()->create([
            'tenant_id' => $config->tenant_id,
            'config_id' => $config->id,
            'version_number' => $nextNumber + 1,
            'status' => TenantSsoConfigVersion::STATUS_ACTIVE,
            'provider_label' => $material['provider_label'] ?? null,
            'issuer_url' => $material['issuer_url'] ?? null,
            'client_id' => $material['client_id'] ?? null,
            'client_secret_encrypted' => $material['client_secret_encrypted'] ?? null,
            // BK-098 foundation: new active versions stay on legacy until explicit cutover.
            'credential_source' => $material['credential_source']
                ?? TenantSsoConfigVersion::CREDENTIAL_SOURCE_LEGACY_ENCRYPTED,
            'credential_provider' => $material['credential_provider'] ?? null,
            'credential_reference' => $material['credential_reference'] ?? null,
            'credential_type' => $material['credential_type'] ?? null,
            'credential_version_policy' => $material['credential_version_policy'] ?? null,
            'credential_environment_scope' => $material['credential_environment_scope'] ?? null,
            'credential_status' => $material['credential_status'] ?? null,
            'credential_last_verified_at' => $material['credential_last_verified_at'] ?? null,
            'redirect_uri' => $material['redirect_uri'] ?? null,
            'jwks_uri' => $material['jwks_uri'] ?? null,
            'logout_token_signing_algs' => $material['logout_token_signing_algs']
                ?? config('identity.sso.default_logout_token_signing_algs', ['RS256']),
            'scopes' => $material['scopes'] ?? config('identity.sso.default_scopes', ['openid', 'profile', 'email']),
            'activated_at' => now(),
            'validated_at' => now(),
            'approved_at' => now(),
            'superseded_at' => null,
        ]);
    }

    protected function supersedeActiveVersion(TenantSsoConfig $config): void
    {
        if (! $config->active_version_id) {
            return;
        }

        $active = TenantSsoConfigVersion::query()
            ->where('config_id', $config->id)
            ->whereKey($config->active_version_id)
            ->first();

        if (! $active || $active->status !== TenantSsoConfigVersion::STATUS_ACTIVE) {
            return;
        }

        $active->forceFill([
            'status' => TenantSsoConfigVersion::STATUS_SUPERSEDED,
            'superseded_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function materialSnapshotFromConfig(TenantSsoConfig $config): array
    {
        $snapshot = [
            'provider_label' => $config->provider_label,
            'issuer_url' => $config->issuer_url,
            'client_id' => $config->client_id,
            'client_secret_encrypted' => $config->getAttributes()['client_secret_encrypted'] ?? null,
            'redirect_uri' => $config->redirect_uri,
            'jwks_uri' => $config->jwks_uri,
            'logout_token_signing_algs' => $config->logout_token_signing_algs,
            'scopes' => $config->scopes,
        ];

        // BK-098: inherit version-owned credential authority so material updates
        // do not silently demote an active reference version to legacy_encrypted.
        if ($config->active_version_id) {
            $active = TenantSsoConfigVersion::query()
                ->where('config_id', $config->id)
                ->whereKey($config->active_version_id)
                ->first();
            if ($active instanceof TenantSsoConfigVersion) {
                $snapshot['credential_source'] = $active->credential_source
                    ?? TenantSsoConfigVersion::CREDENTIAL_SOURCE_LEGACY_ENCRYPTED;
                $snapshot['credential_provider'] = $active->credential_provider;
                $snapshot['credential_reference'] = $active->credential_reference;
                $snapshot['credential_type'] = $active->credential_type;
                $snapshot['credential_version_policy'] = $active->credential_version_policy;
                $snapshot['credential_environment_scope'] = $active->credential_environment_scope;
                $snapshot['credential_status'] = $active->credential_status;
                $snapshot['credential_last_verified_at'] = $active->credential_last_verified_at;
                // Prefer version-row ciphertext for legacy; never invent parent as authority for reference.
                if (($snapshot['credential_source'] ?? null)
                    === TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE) {
                    $snapshot['client_secret_encrypted'] = null;
                } else {
                    $snapshot['client_secret_encrypted'] = $active->getAttributes()['client_secret_encrypted']
                        ?? ($config->getAttributes()['client_secret_encrypted'] ?? null);
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $materialPayload
     * @return array<string, mixed>
     */
    protected function mergeMaterial(TenantSsoConfig $existing, array $materialPayload, bool $secretProvided): array
    {
        $merged = $this->materialSnapshotFromConfig($existing);

        foreach (self::MATERIAL_FIELDS as $field) {
            if ($field === 'client_secret_encrypted') {
                // Reference path: never accept new operational ciphertext via update merge.
                if (($merged['credential_source'] ?? null)
                    === TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE) {
                    $merged[$field] = null;

                    continue;
                }
                if ($secretProvided) {
                    $merged[$field] = $materialPayload[$field];
                }

                continue;
            }

            if (array_key_exists($field, $materialPayload)) {
                $merged[$field] = $materialPayload[$field];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $materialPayload
     */
    protected function materialPayloadDiffers(TenantSsoConfig $existing, array $materialPayload, bool $secretProvided): bool
    {
        if ($secretProvided) {
            return true;
        }

        if ($materialPayload === []) {
            return false;
        }

        $current = $this->materialSnapshotFromConfig($existing);

        foreach ($materialPayload as $field => $value) {
            if (($current[$field] ?? null) != $value) {
                return true;
            }
        }

        return false;
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
            'has_client_secret' => $this->publicHasCredential($record),
            'active_version_id' => $record->active_version_id,
            'rollout_state' => $record->rollout_state ?? TenantSsoConfig::ROLLOUT_ENABLED,
            'pending_version_id' => $record->pending_version_id,
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
            'active_version_id' => null,
            'rollout_state' => TenantSsoConfig::ROLLOUT_DISABLED,
            'pending_version_id' => null,
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
            'has_client_secret' => $this->publicHasCredential($record),
            'active_version_id' => $record->active_version_id,
        ];
    }

    protected function publicHasCredential(TenantSsoConfig $record): bool
    {
        if (! $record->active_version_id) {
            return filled($record->getAttributes()['client_secret_encrypted'] ?? null);
        }

        $version = TenantSsoConfigVersion::query()
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($record->active_version_id)
            ->first();

        if (! $version instanceof TenantSsoConfigVersion) {
            return false;
        }

        return $this->credentialAccess->versionHasUsableCredential($version);
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

    protected function assertActiveTenant(Tenant $tenant): void
    {
        if ($tenant->status !== 'active') {
            abort(403, 'Tenant is not active.');
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
    public function runInTenantContext(Tenant $tenant, callable $callback)
    {
        return $this->withTenantContext($tenant, $callback);
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
