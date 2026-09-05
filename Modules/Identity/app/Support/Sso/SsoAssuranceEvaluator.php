<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS5: evaluate IdP assurance evidence against Tenant MFA policy.
 */
final class SsoAssuranceEvaluator
{
    /** @var list<string> */
    private const MFA_AMR_VALUES = [
        'mfa',
        'otp',
        'totp',
        'sms',
        'hwk',
        'swk',
        'face',
        'fpt',
        'pin',
    ];

    public function __construct(
        protected MfaService $mfaService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $assuranceEvidence
     */
    public function isSufficientForFullSession(Tenant $tenant, TenantUser $user, ?array $assuranceEvidence): bool
    {
        if (! $this->mfaService->isMfaRequired($tenant)) {
            return true;
        }

        $authUser = $user instanceof TenantUser ? $user : TenantUser::query()->whereKey($user->id)->first();
        if (! $authUser instanceof TenantUser) {
            return false;
        }

        if (! $this->mfaService->userHasConfirmedMfa($authUser)) {
            return false;
        }

        return $this->evidenceIndicatesMfa($assuranceEvidence);
    }

    /**
     * @param  array<string, mixed>|null  $assuranceEvidence
     */
    public function evidenceIndicatesMfa(?array $assuranceEvidence): bool
    {
        if (! is_array($assuranceEvidence)) {
            return false;
        }

        $amr = $assuranceEvidence['amr'] ?? null;
        if (is_array($amr)) {
            foreach ($amr as $value) {
                if (! is_string($value)) {
                    continue;
                }
                if (in_array(strtolower($value), self::MFA_AMR_VALUES, true)) {
                    return true;
                }
            }
        }

        $acr = $assuranceEvidence['acr'] ?? null;
        if (is_string($acr) && $acr !== '') {
            $normalized = strtolower($acr);
            if (str_contains($normalized, 'mfa') || str_contains($normalized, 'aal2') || str_contains($normalized, 'aal3')) {
                return true;
            }
        }

        return false;
    }
}
