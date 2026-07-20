<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\SsoBackchannelLogoutEvent;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Sso\SsoBackChannelLogoutTokenValidator;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * BK-082 WS7: OIDC Back-Channel Logout processing (D26).
 */
class SsoBackChannelLogoutService
{
    public function __construct(
        protected SsoBackChannelLogoutTokenValidator $tokenValidator,
        protected SsoConfigService $configService,
        protected SessionRegistryService $sessionRegistry,
        protected SsoSecurityAudit $audit,
    ) {}

    /**
     * @return array{ok: bool, status: int, reason: string, sessions_revoked: int}
     */
    public function handle(string $logoutToken, string $tenantId): array
    {
        if ($logoutToken === '' || $tenantId === '') {
            return $this->reject(null, null, null, 'malformed', 400);
        }

        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if (! $tenant instanceof Tenant || $tenant->status !== 'active') {
            return $this->reject(null, $tenantId, null, 'tenant_inactive', 400);
        }

        $decoded = $this->tokenValidator->decode($logoutToken);
        if ($decoded === null) {
            return $this->reject(null, (string) $tenant->id, null, 'malformed_token', 400);
        }

        $alg = $decoded['header']['alg'] ?? null;
        if (! is_string($alg) || ! in_array($alg, ['HS256', 'RS256'], true)) {
            return $this->reject(null, (string) $tenant->id, null, 'alg_unsupported', 400);
        }

        $version = $this->resolveActiveVersion($tenant);
        if ($version === null) {
            return $this->reject(null, (string) $tenant->id, null, 'idp_config_missing', 400);
        }

        $expectedIssuer = (string) $version->issuer_url;
        $expectedAudience = (string) $version->client_id;
        $claimError = $this->tokenValidator->validateClaims($decoded['payload'], $expectedIssuer, $expectedAudience);
        if ($claimError !== null) {
            return $this->reject(null, (string) $tenant->id, (string) $version->id, $claimError, 400);
        }

        if ($alg === 'HS256') {
            $secret = $this->configService->getDecryptedClientSecretForVersion($tenant, $version);
            if ($secret === null || $secret === ''
                || ! $this->tokenValidator->verifyHmacSha256($decoded['signing_input'], $decoded['signature'], $secret)) {
                return $this->reject(null, (string) $tenant->id, (string) $version->id, 'signature_invalid', 400);
            }
        } else {
            // RS256 production path: require signature verification via IdP JWKS (BK-062 live).
            // Protocol fixtures use HS256; unsigned/unsupported RS256 without verifier fails closed.
            return $this->reject(null, (string) $tenant->id, (string) $version->id, 'alg_unsupported', 400);
        }

        $jti = (string) $decoded['payload']['jti'];
        $jtiHash = SsoSecretCrypto::proof($jti);

        $existing = SsoBackchannelLogoutEvent::query()->where('jti_hash', $jtiHash)->first();
        if ($existing) {
            // Idempotent replay: already processed or rejected — no further session changes.
            $this->audit->record('sso.bclogout.replay', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'reason' => 'jti_replay',
                'status' => $existing->status,
                'sessions_revoked' => 0,
            ]);

            return [
                'ok' => true,
                'status' => 200,
                'reason' => 'idempotent_replay',
                'sessions_revoked' => 0,
            ];
        }

        try {
            $revoked = $this->revokeSessions($tenant, $version, $decoded['payload']);
        } catch (Throwable) {
            // Do not persist jti on internal failure — allow safe retry without double effects.
            $this->audit->record('sso.bclogout.rejected', [
                'tenant_id' => (string) $tenant->id,
                'idp_configuration_version_id' => (string) $version->id,
                'reason' => 'internal_failure',
                'status' => SsoBackchannelLogoutEvent::STATUS_REJECTED,
                'sessions_revoked' => 0,
            ]);

            return [
                'ok' => false,
                'status' => 500,
                'reason' => 'internal_failure',
                'sessions_revoked' => 0,
            ];
        }

        SsoBackchannelLogoutEvent::query()->create([
            'jti_hash' => $jtiHash,
            'tenant_id' => (string) $tenant->id,
            'idp_configuration_version_id' => (string) $version->id,
            'issuer_hash' => SsoSecretCrypto::proof(rtrim($expectedIssuer, '/')),
            'status' => SsoBackchannelLogoutEvent::STATUS_PROCESSED,
            'sessions_revoked' => $revoked,
            'processed_at' => now(),
        ]);

        $this->audit->record('sso.bclogout.processed', [
            'tenant_id' => (string) $tenant->id,
            'idp_configuration_version_id' => (string) $version->id,
            'reason' => 'ok',
            'status' => SsoBackchannelLogoutEvent::STATUS_PROCESSED,
            'sessions_revoked' => $revoked,
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'reason' => 'ok',
            'sessions_revoked' => $revoked,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function revokeSessions(Tenant $tenant, TenantSsoConfigVersion $version, array $payload): int
    {
        $issuer = rtrim((string) $version->issuer_url, '/');
        $sid = isset($payload['sid']) && is_string($payload['sid']) ? $payload['sid'] : null;
        $sub = isset($payload['sub']) && is_string($payload['sub']) ? $payload['sub'] : null;

        tenancy()->initialize($tenant);
        try {
            if (is_string($sid) && $sid !== '') {
                return $this->sessionRegistry->revokeActiveByIdpSid($tenant, $issuer, $sid, (string) $version->id);
            }

            if (! is_string($sub) || $sub === '') {
                return 0;
            }

            $link = TenantUserIdentity::query()
                ->where('tenant_id', $tenant->id)
                ->where('issuer', $issuer)
                ->where('subject', $sub)
                ->first();

            if (! $link) {
                return 0;
            }

            return $this->sessionRegistry->revokeActiveByIdentityLink(
                $tenant,
                (string) $link->id,
                $issuer,
                (string) $version->id,
            );
        } finally {
            tenancy()->end();
        }
    }

    protected function resolveActiveVersion(Tenant $tenant): ?TenantSsoConfigVersion
    {
        if (! $this->configService->isOperationalForTenant($tenant)) {
            return null;
        }

        $versionId = $this->configService->getActiveVersionId($tenant);
        if ($versionId === null) {
            return null;
        }

        return $this->configService->findVersionForTenant($tenant, $versionId);
    }

    /**
     * @return array{ok: bool, status: int, reason: string, sessions_revoked: int}
     */
    protected function reject(?string $jtiHash, ?string $tenantId, ?string $versionId, string $reason, int $status): array
    {
        if (is_string($jtiHash) && $jtiHash !== '') {
            try {
                DB::connection('central')->transaction(function () use ($jtiHash, $tenantId, $versionId, $reason) {
                    if (SsoBackchannelLogoutEvent::query()->where('jti_hash', $jtiHash)->exists()) {
                        return;
                    }
                    SsoBackchannelLogoutEvent::query()->create([
                        'jti_hash' => $jtiHash,
                        'tenant_id' => $tenantId ?? '00000000-0000-0000-0000-000000000000',
                        'idp_configuration_version_id' => $versionId,
                        'status' => SsoBackchannelLogoutEvent::STATUS_REJECTED,
                        'failure_reason' => $reason,
                        'sessions_revoked' => 0,
                        'processed_at' => now(),
                    ]);
                });
            } catch (Throwable) {
                // Fail closed without leaking.
            }
        }

        if (is_string($tenantId) && $tenantId !== '') {
            $this->audit->record('sso.bclogout.rejected', [
                'tenant_id' => $tenantId,
                'idp_configuration_version_id' => $versionId,
                'reason' => $reason,
                'status' => SsoBackchannelLogoutEvent::STATUS_REJECTED,
                'sessions_revoked' => 0,
            ]);
        }

        return [
            'ok' => false,
            'status' => $status,
            'reason' => $reason,
            'sessions_revoked' => 0,
        ];
    }
}
