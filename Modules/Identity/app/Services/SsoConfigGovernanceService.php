<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * BK-082 WS8: IdP configuration lifecycle governance (draft→…→active) + rollout.
 */
class SsoConfigGovernanceService
{
    public function __construct(
        protected SsoConfigService $configService,
        protected SsoSecurityAudit $audit,
    ) {}

    public function validateVersion(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            $version = $this->requireVersion($tenant, $versionId);
            $this->assertTransition($version, TenantSsoConfigVersion::STATUS_DRAFT, 'validate');

            $version->forceFill([
                'status' => TenantSsoConfigVersion::STATUS_VALIDATED,
                'validated_at' => now(),
            ])->save();

            $this->audit->record('sso.governance.validated', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => $version->status,
                'reason' => 'ok',
            ]);

            return $version->fresh();
        });
    }

    public function markTestOnly(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            $version = $this->requireVersion($tenant, $versionId);
            if (! in_array($version->status, [
                TenantSsoConfigVersion::STATUS_DRAFT,
                TenantSsoConfigVersion::STATUS_VALIDATED,
            ], true)) {
                throw new SsoSecurityException('Only draft/validated versions can enter test-only.');
            }

            $version->forceFill([
                'status' => TenantSsoConfigVersion::STATUS_TEST_ONLY,
                'validated_at' => $version->validated_at ?? now(),
            ])->save();

            $config = $this->requireConfig($tenant);
            $config->forceFill([
                'pending_version_id' => $version->id,
                'rollout_state' => TenantSsoConfig::ROLLOUT_TEST_ONLY,
            ])->save();

            $this->audit->record('sso.governance.test_only', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => $version->status,
                'reason' => 'ok',
            ]);

            return $version->fresh();
        });
    }

    public function approveVersion(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            $version = $this->requireVersion($tenant, $versionId);
            if (! in_array($version->status, [
                TenantSsoConfigVersion::STATUS_VALIDATED,
                TenantSsoConfigVersion::STATUS_TEST_ONLY,
            ], true)) {
                throw new SsoSecurityException('Version must be validated or test-only before approval.');
            }

            // Test-only must never auto-activate.
            $version->forceFill([
                'status' => TenantSsoConfigVersion::STATUS_APPROVED,
                'approved_at' => now(),
            ])->save();

            $this->audit->record('sso.governance.approved', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => $version->status,
                'reason' => 'ok',
            ]);

            return $version->fresh();
        });
    }

    /**
     * Atomic activation: supersede previous active, point active_version_id, set rollout enabled.
     */
    public function activateVersion(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            return DB::connection('tenant')->transaction(function () use ($tenant, $versionId) {
                $config = $this->requireConfig($tenant);
                if ($config->rollout_state === TenantSsoConfig::ROLLOUT_SECURITY_DISABLED) {
                    throw new SsoSecurityException('Re-enablement after security disable requires explicit recovery.');
                }

                $version = $this->requireVersion($tenant, $versionId);
                if ($version->status === TenantSsoConfigVersion::STATUS_TEST_ONLY) {
                    throw new SsoSecurityException('Test-only versions cannot auto-activate to production.');
                }
                if ($version->status !== TenantSsoConfigVersion::STATUS_APPROVED) {
                    throw new SsoSecurityException('Only approved versions may be activated.');
                }
                if ($version->secret_revoked_at !== null) {
                    throw new SsoSecurityException('Cannot activate a version with revoked secret.');
                }

                if ($config->active_version_id && (string) $config->active_version_id !== (string) $version->id) {
                    $previous = TenantSsoConfigVersion::query()
                        ->where('config_id', $config->id)
                        ->whereKey($config->active_version_id)
                        ->lockForUpdate()
                        ->first();
                    if ($previous && $previous->status === TenantSsoConfigVersion::STATUS_ACTIVE) {
                        $previous->forceFill([
                            'status' => TenantSsoConfigVersion::STATUS_SUPERSEDED,
                            'superseded_at' => now(),
                        ])->save();
                    }
                }

                $version = TenantSsoConfigVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

                app(\Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService::class)
                    ->assertOperationalCredentialReady($version);

                $version->forceFill([
                    'status' => TenantSsoConfigVersion::STATUS_ACTIVE,
                    'activated_at' => now(),
                    'approved_at' => $version->approved_at ?? now(),
                ])->save();

                $material = [
                    'provider_label' => $version->provider_label,
                    'issuer_url' => $version->issuer_url,
                    'client_id' => $version->client_id,
                    'redirect_uri' => $version->redirect_uri,
                    'jwks_uri' => $version->jwks_uri,
                    'logout_token_signing_algs' => $version->logout_token_signing_algs,
                    'scopes' => $version->scopes,
                    'approved_email_domains' => $version->approved_email_domains,
                ];

                $config->forceFill(array_merge($material, [
                    'active_version_id' => $version->id,
                    'pending_version_id' => null,
                    'enabled' => true,
                    'rollout_state' => TenantSsoConfig::ROLLOUT_ENABLED,
                    'security_disabled_at' => null,
                    'security_disable_reason' => null,
                ]))->save();

                app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)
                    ->invalidateReadyForTenant($tenant, 'idp_configuration_version_activated');

                $this->audit->record('sso.governance.activated', [
                    'tenant_id' => (string) $tenant->id,
                    'idp_configuration_version_id' => (string) $version->id,
                    'status' => TenantSsoConfigVersion::STATUS_ACTIVE,
                    'reason' => 'ok',
                ]);

                return $version->fresh();
            });
        });
    }

    public function setRolloutState(Tenant $tenant, string $state): TenantSsoConfig
    {
        if (! in_array($state, TenantSsoConfig::ROLLOUT_STATES, true)) {
            throw new SsoSecurityException('Invalid rollout state.');
        }

        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $state) {
            $config = $this->requireConfig($tenant);
            if ($config->rollout_state === TenantSsoConfig::ROLLOUT_SECURITY_DISABLED
                && $state !== TenantSsoConfig::ROLLOUT_SECURITY_DISABLED) {
                throw new SsoSecurityException('Security-disabled rollout requires recovery workflow.');
            }

            $config->forceFill(['rollout_state' => $state])->save();

            $this->audit->record('sso.governance.rollout', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => $config->active_version_id,
                'status' => $state,
                'reason' => 'rollout_updated',
            ]);

            return $config->fresh();
        });
    }

    /**
     * @param  list<string>  $userIds
     */
    public function setPilotUsers(Tenant $tenant, array $userIds): TenantSsoConfig
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $userIds) {
            $hashes = [];
            foreach ($userIds as $userId) {
                if (is_string($userId) && $userId !== '') {
                    $hashes[] = SsoSecretCrypto::proof($userId);
                }
            }

            $config = $this->requireConfig($tenant);
            $config->forceFill(['pilot_user_id_hashes' => array_values(array_unique($hashes))])->save();

            return $config->fresh();
        });
    }

    /**
     * Create a draft version from current material without activating (SoD configure path).
     */
    public function createDraftFromMaterial(Tenant $tenant, array $material): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $material) {
            $config = $this->requireConfig($tenant);
            $nextNumber = (int) TenantSsoConfigVersion::query()
                ->where('config_id', $config->id)
                ->max('version_number');

            // Inherit active credential metadata when cloning material without a new secret.
            if ($config->active_version_id && ! array_key_exists('credential_reference', $material)) {
                $active = TenantSsoConfigVersion::query()
                    ->where('config_id', $config->id)
                    ->whereKey($config->active_version_id)
                    ->first();
                if ($active
                    && filled($active->credential_provider)
                    && filled($active->credential_reference)) {
                    $material['credential_provider'] = $active->credential_provider;
                    $material['credential_reference'] = $active->credential_reference;
                    $material['credential_type'] = $active->credential_type;
                    $material['credential_version_policy'] = $active->credential_version_policy;
                    $material['credential_environment_scope'] = $active->credential_environment_scope;
                    $material['credential_status'] = $active->credential_status;
                    $material['credential_last_verified_at'] = $active->credential_last_verified_at;
                }
            }

            if (blank($material['credential_provider'] ?? null)
                || blank($material['credential_reference'] ?? null)
                || blank($material['credential_type'] ?? null)
                || blank($material['credential_environment_scope'] ?? null)
                || ($material['credential_status'] ?? null) !== 'active') {
                throw new SsoSecurityException('Drafts require complete credential reference metadata.');
            }

            $version = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $config->tenant_id,
                'config_id' => $config->id,
                'version_number' => $nextNumber + 1,
                'status' => TenantSsoConfigVersion::STATUS_DRAFT,
                'provider_label' => $material['provider_label'] ?? $config->provider_label,
                'issuer_url' => $material['issuer_url'] ?? $config->issuer_url,
                'client_id' => $material['client_id'] ?? $config->client_id,
                'credential_provider' => $material['credential_provider'],
                'credential_reference' => $material['credential_reference'],
                'credential_type' => $material['credential_type'],
                'credential_version_policy' => $material['credential_version_policy'] ?? null,
                'credential_environment_scope' => $material['credential_environment_scope'],
                'credential_status' => $material['credential_status'],
                'credential_last_verified_at' => $material['credential_last_verified_at'] ?? null,
                'redirect_uri' => $material['redirect_uri'] ?? $config->redirect_uri,
                'jwks_uri' => $material['jwks_uri'] ?? $config->jwks_uri,
                'logout_token_signing_algs' => $material['logout_token_signing_algs']
                    ?? $config->logout_token_signing_algs,
                'scopes' => $material['scopes'] ?? $config->scopes,
                'approved_email_domains' => $material['approved_email_domains'] ?? $config->approved_email_domains,
            ]);

            $config->forceFill(['pending_version_id' => $version->id])->save();

            $this->audit->record('sso.governance.draft_created', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => TenantSsoConfigVersion::STATUS_DRAFT,
                'reason' => 'ok',
            ]);

            return $version;
        });
    }

    /**
     * Recovery after security disable: requires a still-trusted approved/validated version.
     */
    public function recoverFromSecurityDisable(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            $config = $this->requireConfig($tenant);
            if ($config->rollout_state !== TenantSsoConfig::ROLLOUT_SECURITY_DISABLED) {
                throw new SsoSecurityException('Tenant is not security-disabled.');
            }

            $version = $this->requireVersion($tenant, $versionId);
            if ($version->secret_revoked_at !== null) {
                throw new SsoSecurityException('Cannot restore a revoked secret.');
            }
            if (! in_array($version->status, [
                TenantSsoConfigVersion::STATUS_APPROVED,
                TenantSsoConfigVersion::STATUS_VALIDATED,
                TenantSsoConfigVersion::STATUS_ACTIVE,
            ], true)) {
                throw new SsoSecurityException('Recovery requires a revalidated trusted version.');
            }

            if ($version->status !== TenantSsoConfigVersion::STATUS_APPROVED) {
                $version->forceFill([
                    'status' => TenantSsoConfigVersion::STATUS_APPROVED,
                    'approved_at' => now(),
                ])->save();
            }

            $config->forceFill([
                'rollout_state' => TenantSsoConfig::ROLLOUT_DISABLED,
                'security_disabled_at' => null,
                'security_disable_reason' => null,
            ])->save();

            $this->audit->record('sso.governance.reenabled', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => 'recovery_pending_activate',
                'reason' => 'ok',
            ]);

            return $this->activateVersion($tenant, (string) $version->id);
        });
    }

    protected function requireConfig(Tenant $tenant): TenantSsoConfig
    {
        $config = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->first();
        if (! $config) {
            throw new RuntimeException('SSO config missing.');
        }

        return $config;
    }

    protected function requireVersion(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        $version = TenantSsoConfigVersion::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($versionId)
            ->first();
        if (! $version) {
            throw new SsoSecurityException('IdP configuration version not found.');
        }

        return $version;
    }

    protected function assertTransition(TenantSsoConfigVersion $version, string $from, string $action): void
    {
        if ($version->status !== $from) {
            throw new SsoSecurityException("Cannot {$action} version in status {$version->status}.");
        }
    }
}
