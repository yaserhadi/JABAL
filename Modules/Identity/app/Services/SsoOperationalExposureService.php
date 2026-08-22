<?php

namespace Modules\Identity\Services;

use App\Support\Tenancy\TenantAddressingProfile;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS9: boolean Host/Path SSO login exposure without leaking internals.
 */
class SsoOperationalExposureService
{
    public function __construct(
        protected SsoConfigService $configService,
        protected SsoOperationalGate $operationalGate,
        protected TenantAddressingProfile $addressing,
    ) {}

    /**
     * Whether the Tenant login page may advertise Enterprise SSO.
     * Failures are collapsed to false — never expose why.
     */
    public function isExposedOnTenantLogin(Tenant $tenant, ?string $actorUserId = null): bool
    {
        if ($tenant->status !== 'active') {
            return false;
        }

        // WAVE-3 GAP-009: do not advertise SSO when Authentication Policy denies SSO LOGIN.
        if (! app(\Modules\Identity\Support\Auth\AuthenticationLoginPolicy::class)->allowsSsoLogin($tenant)) {
            return false;
        }

        if ($this->addressing->isPath()) {
            return $this->configService->isOperationalForTenant($tenant);
        }

        if (! $this->addressing->isHost()) {
            return false;
        }

        try {
            $this->operationalGate->assertMayProceed(
                $tenant,
                SsoOperationalGate::STAGE_INITIATION,
                null,
                $actorUserId,
                allowTestOnly: false,
            );

            return true;
        } catch (SsoSecurityException) {
            return false;
        }
    }

    /**
     * Opaque start URL for the login button (Host enterprise start vs Path redirect).
     * Caller must already have decided exposure; this only builds the URL.
     */
    public function startUrlForTenantLogin(Tenant $tenant): string
    {
        if ($this->addressing->isHost()) {
            $scheme = $this->addressing->canonicalScheme() ?: 'https';
            $host = request()->getHost();

            return $scheme.'://'.$host.'/auth/enterprise-sso/start';
        }

        return route('identity.sso.redirect', ['tenant' => $tenant->id], absolute: true);
    }
}
