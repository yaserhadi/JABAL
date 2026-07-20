<?php

namespace Modules\Identity\Services;

use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\SsoPlatformControl;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS8: authoritative revalidation of rollout / kill / version state (D34).
 */
class SsoOperationalGate
{
    public const STAGE_INITIATION = 'initiation';

    public const STAGE_AUTH_ADVANCE = 'auth_advance';

    public const STAGE_HANDOFF_ISSUE = 'handoff_issue';

    public const STAGE_SESSION_CREATE = 'session_create';

    public function __construct(
        protected SsoConfigService $configService,
    ) {}

    /**
     * @throws SsoSecurityException
     */
    public function assertMayProceed(
        Tenant $tenant,
        string $stage,
        ?string $boundVersionId = null,
        ?string $actorUserId = null,
        bool $allowTestOnly = false,
    ): void {
        $platform = SsoPlatformControl::current();
        if ($platform->disable_enterprise_sso) {
            throw new SsoSecurityException('Enterprise SSO is disabled platform-wide.');
        }

        if (in_array($stage, [self::STAGE_INITIATION, self::STAGE_AUTH_ADVANCE], true)
            && $platform->pause_new_initiations) {
            throw new SsoSecurityException('Enterprise SSO initiations are paused.');
        }

        if ($tenant->status !== 'active') {
            throw new SsoSecurityException('Tenant is not active.');
        }

        $this->configService->runInTenantContext($tenant, function () use (
            $tenant,
            $stage,
            $boundVersionId,
            $actorUserId,
            $allowTestOnly,
        ): void {
            $config = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->first();
            if (! $config) {
                throw new SsoSecurityException('Tenant SSO is not configured.');
            }

            if ($config->disabled_by_entitlement || ! $config->enabled) {
                throw new SsoSecurityException('Tenant SSO is not enabled.');
            }

            $rollout = (string) ($config->rollout_state ?: TenantSsoConfig::ROLLOUT_ENABLED);
            if ($rollout === TenantSsoConfig::ROLLOUT_SECURITY_DISABLED) {
                throw new SsoSecurityException('Tenant SSO is security-disabled.');
            }
            if ($rollout === TenantSsoConfig::ROLLOUT_DISABLED) {
                throw new SsoSecurityException('Tenant SSO rollout is disabled.');
            }
            if ($rollout === TenantSsoConfig::ROLLOUT_PAUSED
                && in_array($stage, [self::STAGE_INITIATION, self::STAGE_AUTH_ADVANCE], true)) {
                throw new SsoSecurityException('Tenant SSO rollout is paused.');
            }

            $version = $this->resolveVersion($tenant, $config, $boundVersionId);
            if ($version === null) {
                throw new SsoSecurityException('Active IdP configuration version is required.');
            }

            if ($version->secret_revoked_at !== null) {
                throw new SsoSecurityException('IdP configuration secret is revoked.');
            }

            if ($version->status === TenantSsoConfigVersion::STATUS_SUPERSEDED
                || $version->status === TenantSsoConfigVersion::STATUS_DISABLED) {
                if ($stage === self::STAGE_INITIATION || $stage === self::STAGE_AUTH_ADVANCE) {
                    throw new SsoSecurityException('IdP configuration version cannot serve new login.');
                }
            }

            $testOnly = $version->isTestOnly() || $rollout === TenantSsoConfig::ROLLOUT_TEST_ONLY;
            if ($testOnly) {
                if (! $allowTestOnly) {
                    throw new SsoSecurityException('Test-only SSO is not available for this actor.');
                }
                if ($stage === self::STAGE_SESSION_CREATE) {
                    throw new SsoSecurityException('Test-only SSO cannot create production sessions.');
                }
                if ($rollout === TenantSsoConfig::ROLLOUT_PILOT || $rollout === TenantSsoConfig::ROLLOUT_TEST_ONLY) {
                    $this->assertPilotActor($config, $actorUserId);
                }
            }

            if ($rollout === TenantSsoConfig::ROLLOUT_PILOT && ! $testOnly) {
                $this->assertPilotActor($config, $actorUserId);
            }

            if ($stage === self::STAGE_INITIATION || $stage === self::STAGE_AUTH_ADVANCE) {
                if (! $version->mayServeNewProductionLogin() && ! $testOnly) {
                    throw new SsoSecurityException('IdP configuration version is not active for production login.');
                }
            }

            if ($stage === self::STAGE_SESSION_CREATE && $testOnly) {
                throw new SsoSecurityException('Test-only SSO cannot create production sessions.');
            }
        });
    }

    protected function resolveVersion(
        Tenant $tenant,
        TenantSsoConfig $config,
        ?string $boundVersionId,
    ): ?TenantSsoConfigVersion {
        if (is_string($boundVersionId) && $boundVersionId !== '') {
            return TenantSsoConfigVersion::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($boundVersionId)
                ->first();
        }

        if (! is_string($config->active_version_id) || $config->active_version_id === '') {
            return null;
        }

        return TenantSsoConfigVersion::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($config->active_version_id)
            ->first();
    }

    protected function assertPilotActor(TenantSsoConfig $config, ?string $actorUserId): void
    {
        if (! is_string($actorUserId) || $actorUserId === '') {
            throw new SsoSecurityException('Pilot/test SSO requires an explicit pilot actor.');
        }

        $hashes = $config->pilot_user_id_hashes;
        if (! is_array($hashes) || $hashes === []) {
            throw new SsoSecurityException('Pilot/test SSO has no approved pilot users.');
        }

        $proof = SsoSecretCrypto::proof($actorUserId);
        foreach ($hashes as $hash) {
            if (is_string($hash) && hash_equals($hash, $proof)) {
                return;
            }
        }

        throw new SsoSecurityException('Actor is not an approved pilot user.');
    }
}
