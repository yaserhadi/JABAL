<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Http\Request;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Tenancy\Models\Tenant;

/**
 * Valid Session ≠ Fresh Session for Enterprise SSO first-link (WAVE-1).
 * Requires fresh Password confirmation AND MFA for purpose sso.first_link.
 * Ordinary session activity / idle extension does not count.
 */
final class SsoFirstLinkAssurance
{
    public const PURPOSE = 'sso.first_link';

    public const SESSION_PASSWORD_AT = 'sso.first_link.password_confirmed_at';

    public const SESSION_RETURN_URL = 'sso.first_link.return_url';

    public const SESSION_MFA_INTENT = 'mfa_step_up.intended_purpose';

    public function __construct(
        protected MfaService $mfaService,
        protected SsoSecurityAudit $audit,
    ) {}

    public function freshnessTtlSeconds(): int
    {
        $ttl = (int) config('identity.sso.first_link_freshness_ttl', 900);

        return $ttl > 0 ? $ttl : 900;
    }

    public function passwordIsFresh(): bool
    {
        $at = session(self::SESSION_PASSWORD_AT);
        if (! is_int($at) && ! is_numeric($at)) {
            return false;
        }

        return ((int) $at + $this->freshnessTtlSeconds()) > now()->timestamp;
    }

    public function mfaIsFresh(): bool
    {
        return MfaVerificationContext::isVerified(self::PURPOSE);
    }

    public function isSatisfied(TenantUser $user, Tenant $tenant): bool
    {
        if (! $this->passwordIsFresh()) {
            return false;
        }

        if (! $this->mfaService->userHasConfirmedMfa($user)) {
            return false;
        }

        return $this->mfaIsFresh();
    }

    public function markPasswordConfirmed(): void
    {
        session()->put(self::SESSION_PASSWORD_AT, now()->timestamp);
    }

    /**
     * Consume after a successful first-link so the proof cannot be reused indefinitely.
     */
    public function consume(): void
    {
        session()->forget(self::SESSION_PASSWORD_AT);
        session()->forget(self::SESSION_RETURN_URL);
        session()->forget(self::SESSION_MFA_INTENT);
        if (MfaVerificationContext::isVerified(self::PURPOSE)) {
            MfaVerificationContext::clear();
        }
    }

    /**
     * Test helper: mark Password+MFA first-link proof without UI.
     */
    public static function markSatisfiedForTests(): void
    {
        session()->put(self::SESSION_PASSWORD_AT, now()->timestamp);
        MfaVerificationContext::markVerified(self::PURPOSE, 900);
    }

    public function rememberReturnUrl(string $url): void
    {
        session()->put(self::SESSION_RETURN_URL, $url);
        session()->put(self::SESSION_MFA_INTENT, self::PURPOSE);
    }

    public function pullReturnUrl(): ?string
    {
        $url = session(self::SESSION_RETURN_URL);

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function auditStepUpRequired(string $tenantId, string $reason): void
    {
        $this->audit->record('sso.trust.first_link_step_up_required', [
            'tenant_id' => $tenantId,
            'purpose' => self::PURPOSE,
            'reason' => $reason,
            'status' => 'step_up_required',
        ]);
    }

    public function auditFirstLinkSucceeded(string $tenantId, string $identityLinkId): void
    {
        $this->audit->record('sso.enrollment.first_link_assured', [
            'tenant_id' => $tenantId,
            'identity_link_id' => $identityLinkId,
            'purpose' => self::PURPOSE,
            'status' => 'ok',
            'reason' => 'fresh_password_mfa',
        ]);
    }
}
