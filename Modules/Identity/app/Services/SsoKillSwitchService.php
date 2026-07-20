<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\SsoPlatformControl;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS8: authoritative kill switches + security-disable (D34).
 */
class SsoKillSwitchService
{
    public function __construct(
        protected SsoConfigService $configService,
        protected AuthenticationTransactionService $transactions,
        protected SessionRegistryService $sessions,
        protected SsoSecurityAudit $audit,
    ) {}

    public function pausePlatformInitiations(bool $paused = true): SsoPlatformControl
    {
        $control = SsoPlatformControl::current();
        $control->forceFill(['pause_new_initiations' => $paused])->save();

        $this->audit->record('sso.killswitch.platform_pause', [
            'tenant_id' => null,
            'status' => $paused ? 'paused' : 'resumed',
            'reason' => 'platform',
            'sessions_revoked' => 0,
        ]);

        return $control->fresh();
    }

    public function disablePlatformEnterpriseSso(bool $disabled = true): SsoPlatformControl
    {
        $control = SsoPlatformControl::current();
        $control->forceFill(['disable_enterprise_sso' => $disabled])->save();

        if ($disabled) {
            $cancelled = $this->transactions->cancelOpenEverywhere('platform_security_disable');
            $this->audit->record('sso.killswitch.platform_disable', [
                'tenant_id' => null,
                'status' => 'disabled',
                'reason' => 'platform_security_disable',
                'sessions_revoked' => 0,
                'event_id' => (string) $cancelled,
            ]);
        } else {
            $this->audit->record('sso.killswitch.platform_disable', [
                'tenant_id' => null,
                'status' => 'enabled',
                'reason' => 'platform_reenable',
                'sessions_revoked' => 0,
            ]);
        }

        return $control->fresh();
    }

    public function pauseTenant(Tenant $tenant): TenantSsoConfig
    {
        return $this->setTenantRollout($tenant, TenantSsoConfig::ROLLOUT_PAUSED, 'tenant_pause', false);
    }

    public function disableTenant(Tenant $tenant): TenantSsoConfig
    {
        return $this->setTenantRollout($tenant, TenantSsoConfig::ROLLOUT_DISABLED, 'tenant_disable', true);
    }

    /**
     * Security disable: block initiation/session, cancel in-flight, optionally revoke sessions for version.
     */
    public function securityDisableTenant(
        Tenant $tenant,
        string $reasonCode,
        bool $revokeSessions = true,
    ): TenantSsoConfig {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $reasonCode, $revokeSessions) {
            $config = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $versionId = is_string($config->active_version_id) ? $config->active_version_id : null;

            $config->forceFill([
                'rollout_state' => TenantSsoConfig::ROLLOUT_SECURITY_DISABLED,
                'security_disabled_at' => now(),
                'security_disable_reason' => substr($reasonCode, 0, 64),
                'enabled' => false,
            ])->save();

            $cancelled = $this->transactions->cancelOpenForTenant((string) $tenant->id, 'security_disable');
            $revoked = 0;
            if ($revokeSessions && $versionId !== null) {
                $revoked = $this->sessions->revokeActiveByIdpConfigurationVersion($tenant, $versionId);
            }

            $this->audit->record('sso.killswitch.security_disable', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => $versionId,
                'status' => TenantSsoConfig::ROLLOUT_SECURITY_DISABLED,
                'reason' => $reasonCode,
                'sessions_revoked' => $revoked,
                'event_id' => (string) $cancelled,
            ]);

            return $config->fresh();
        });
    }

    public function disableVersion(Tenant $tenant, string $versionId, string $reasonCode = 'version_disable'): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId, $reasonCode) {
            return DB::connection('tenant')->transaction(function () use ($tenant, $versionId, $reasonCode) {
                $version = TenantSsoConfigVersion::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($versionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $version->forceFill([
                    'status' => TenantSsoConfigVersion::STATUS_DISABLED,
                    'disabled_at' => now(),
                    'disable_reason' => substr($reasonCode, 0, 64),
                ])->save();

                $config = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->firstOrFail();
                if ((string) $config->active_version_id === (string) $version->id) {
                    $config->forceFill([
                        'active_version_id' => null,
                        'enabled' => false,
                        'rollout_state' => TenantSsoConfig::ROLLOUT_DISABLED,
                    ])->save();
                }

                $cancelled = $this->transactions->cancelOpenForVersion(
                    (string) $tenant->id,
                    (string) $version->id,
                    'version_disable'
                );

                $this->audit->record('sso.killswitch.version_disable', [
                    'tenant_id' => (string) $tenant->id,
                    'idp_configuration_version_id' => (string) $version->id,
                    'status' => TenantSsoConfigVersion::STATUS_DISABLED,
                    'reason' => $reasonCode,
                    'sessions_revoked' => 0,
                    'event_id' => (string) $cancelled,
                ]);

                return $version->fresh();
            });
        });
    }

    public function revokeVersionSecret(Tenant $tenant, string $versionId): TenantSsoConfigVersion
    {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $versionId) {
            $version = TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($versionId)
                ->firstOrFail();

            $version->forceFill([
                'secret_revoked_at' => now(),
            ])->save();

            $this->audit->record('sso.governance.secret_revoked', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'status' => $version->status,
                'reason' => 'secret_revoked',
                'sessions_revoked' => 0,
            ]);

            return $version->fresh();
        });
    }

    protected function setTenantRollout(
        Tenant $tenant,
        string $state,
        string $reason,
        bool $cancelInFlight,
    ): TenantSsoConfig {
        return $this->configService->runInTenantContext($tenant, function () use ($tenant, $state, $reason, $cancelInFlight) {
            $config = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $config->forceFill(['rollout_state' => $state])->save();

            $cancelled = 0;
            if ($cancelInFlight) {
                $cancelled = $this->transactions->cancelOpenForTenant((string) $tenant->id, $reason);
            }

            $this->audit->record('sso.killswitch.tenant', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => $config->active_version_id,
                'status' => $state,
                'reason' => $reason,
                'sessions_revoked' => 0,
                'event_id' => (string) $cancelled,
            ]);

            return $config->fresh();
        });
    }
}
