<?php

namespace Modules\Identity\Support\Auth;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4: Valid Admin Session ≠ Fresh Admin Session for Authentication Administration.
 * Fresh Password confirmation + MFA (when enrolled/required) for a purpose-scoped window.
 */
final class AuthenticationAdministrationAssurance
{
    public const PURPOSE_PREFIX = 'auth_admin.';

    public const SESSION_PASSWORD_AT = 'auth_admin.password_confirmed_at';

    public const SESSION_PURPOSE = 'auth_admin.purpose';

    public const SESSION_MFA_INTENT = 'mfa_step_up.intended_purpose';

    public const OP_RESET_PASSWORD = 'auth_admin.reset_password';

    public const OP_RESET_MFA = 'auth_admin.reset_mfa';

    public const OP_RESET_SSO = 'auth_admin.reset_sso';

    public const OP_CHANGE_POLICY = 'auth_admin.change_policy';

    public const OP_CHANGE_EMAIL = 'auth_admin.change_email';

    public const OP_IDP_MIGRATION = 'auth_admin.idp_migration';

    public function __construct(
        protected MfaService $mfaService,
        protected SsoSecurityAudit $audit,
    ) {}

    public function freshnessTtlSeconds(): int
    {
        $ttl = (int) config('identity.security.auth_admin_freshness_ttl', 900);

        return $ttl > 0 ? $ttl : 900;
    }

    public function markPasswordConfirmed(string $purpose): void
    {
        session()->put(self::SESSION_PASSWORD_AT, now()->timestamp);
        session()->put(self::SESSION_PURPOSE, $purpose);
        session()->put(self::SESSION_MFA_INTENT, $purpose);
    }

    public function passwordIsFresh(string $purpose): bool
    {
        if ((string) session(self::SESSION_PURPOSE) !== $purpose) {
            return false;
        }

        $at = session(self::SESSION_PASSWORD_AT);
        if (! is_int($at) && ! is_numeric($at)) {
            return false;
        }

        return ((int) $at + $this->freshnessTtlSeconds()) > now()->timestamp;
    }

    public function mfaIsFresh(string $purpose): bool
    {
        return MfaVerificationContext::isVerified($purpose);
    }

    public function isSatisfied(TenantUser $admin, Tenant $tenant, string $purpose): bool
    {
        if (! $this->passwordIsFresh($purpose)) {
            return false;
        }

        if (! $this->mfaService->userHasConfirmedMfa($admin)) {
            // Admin without MFA: Password freshness alone is accepted only when MFA not enrolled.
            // If MFA is required for tenant, still fail closed.
            if ($this->mfaService->isMfaRequired($tenant)) {
                return false;
            }

            return true;
        }

        return $this->mfaIsFresh($purpose);
    }

    public function consume(string $purpose): void
    {
        if ((string) session(self::SESSION_PURPOSE) === $purpose) {
            session()->forget(self::SESSION_PASSWORD_AT);
            session()->forget(self::SESSION_PURPOSE);
        }
        if ((string) session(self::SESSION_MFA_INTENT) === $purpose) {
            session()->forget(self::SESSION_MFA_INTENT);
        }
        if (MfaVerificationContext::isVerified($purpose)) {
            MfaVerificationContext::clear();
        }
    }

    /** @internal tests */
    public static function markSatisfiedForTests(string $purpose): void
    {
        session()->put(self::SESSION_PASSWORD_AT, now()->timestamp);
        session()->put(self::SESSION_PURPOSE, $purpose);
        session()->put(self::SESSION_MFA_INTENT, $purpose);
        MfaVerificationContext::markVerified($purpose, 900);
    }
}
