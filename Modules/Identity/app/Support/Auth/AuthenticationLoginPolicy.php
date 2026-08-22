<?php

namespace Modules\Identity\Support\Auth;

use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SsoReadinessAccountingService;
use Modules\Identity\Services\TemporaryPasswordRecoveryService;
use Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * WAVE-3 GAP-009 + WAVE-5 exceptions / temporary recovery for LOGIN method permission.
 *
 * Credential readiness (Password exists / SSO Ready) ≠ LOGIN permission.
 * SSO-only denies Password LOGIN but must not delete/null the Password credential.
 * Exceptions and temporary PEA recovery may permit Password LOGIN without changing policy.
 */
final class AuthenticationLoginPolicy
{
    public const PASSWORD = 'password';

    public const SSO = 'sso';

    public const BOTH = 'both';

    /** @var list<string> */
    public const MODES = [self::PASSWORD, self::SSO, self::BOTH];

    public function __construct(
        protected SecurityPolicyService $securityPolicies,
    ) {}

    public function mode(Tenant $tenant): string
    {
        $mode = strtolower(trim((string) $this->securityPolicies->getAuthenticationPolicy($tenant)));

        return in_array($mode, self::MODES, true) ? $mode : self::BOTH;
    }

    public function allowsPasswordLogin(Tenant $tenant, ?TenantUser $user = null): bool
    {
        $mode = $this->mode($tenant);

        if ($mode === self::PASSWORD || $mode === self::BOTH) {
            return true;
        }

        // SSO-only: Password operational LOGIN denied unless exception or temporary recovery.
        if ($user === null) {
            return false;
        }

        if (app(SsoReadinessAccountingService::class)->hasValidException($tenant, $user)) {
            return true;
        }

        return app(TemporaryPasswordRecoveryService::class)->hasActiveRecovery($tenant, $user);
    }

    public function allowsSsoLogin(Tenant $tenant): bool
    {
        $mode = $this->mode($tenant);

        return $mode === self::SSO || $mode === self::BOTH;
    }

    public function assertPasswordLoginAllowed(Tenant $tenant, ?TenantUser $user = null): void
    {
        if ($this->allowsPasswordLogin($tenant, $user)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('Password sign-in is not permitted for this organization.'),
        ]);
    }

    public function assertSsoLoginAllowed(Tenant $tenant): void
    {
        if ($this->allowsSsoLogin($tenant)) {
            return;
        }

        throw new SsoSecurityException('sso_login_denied_by_authentication_policy');
    }

    public static function normalize(?string $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, self::MODES, true) ? $mode : self::BOTH;
    }
}
