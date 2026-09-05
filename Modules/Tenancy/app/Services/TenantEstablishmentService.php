<?php

namespace Modules\Tenancy\Services;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Tenancy\Models\LegalOrganizationBusinessOwner;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-115 J0-04: Tenant-establishment completion (OD-2).
 *
 * Business Owner relationship may be Active before MFA.
 * Establishment completion requires Active AND BO MFA satisfied.
 * Does not redefine Active; does not auto-enroll MFA.
 */
class TenantEstablishmentService
{
    public function __construct(
        private readonly MfaService $mfaService,
    ) {}

    /**
     * True when an Active Business Owner exists for the Tenant's Legal Organization
     * and that principal has confirmed MFA enrollment.
     */
    public function isEstablishmentComplete(Tenant $tenant): bool
    {
        $result = $this->evaluate($tenant);

        return $result['complete'];
    }

    /**
     * @return array{
     *   complete: bool,
     *   business_owner_active: bool,
     *   business_owner_mfa_satisfied: bool,
     *   business_owner_user_id: ?string,
     *   detail: string
     * }
     */
    public function evaluate(Tenant $tenant): array
    {
        $orgId = $tenant->legal_organization_id;
        if (! $orgId) {
            return [
                'complete' => false,
                'business_owner_active' => false,
                'business_owner_mfa_satisfied' => false,
                'business_owner_user_id' => null,
                'detail' => 'Tenant has no Legal Organization; Business Owner relationship cannot be Active.',
            ];
        }

        $owner = LegalOrganizationBusinessOwner::query()
            ->where('legal_organization_id', $orgId)
            ->where('status', 'active')
            ->orderByDesc('assigned_at')
            ->first();

        if (! $owner) {
            return [
                'complete' => false,
                'business_owner_active' => false,
                'business_owner_mfa_satisfied' => false,
                'business_owner_user_id' => null,
                'detail' => 'No Active Business Owner relationship for this Legal Organization.',
            ];
        }

        $mfaSatisfied = $this->businessOwnerMfaSatisfied($tenant, (string) $owner->user_id);
        $complete = $mfaSatisfied;

        return [
            'complete' => $complete,
            'business_owner_active' => true,
            'business_owner_mfa_satisfied' => $mfaSatisfied,
            'business_owner_user_id' => (string) $owner->user_id,
            'detail' => $complete
                ? 'Business Owner Active and mandatory MFA satisfied.'
                : 'Business Owner Active but mandatory Business Owner MFA is not satisfied; establishment incomplete.',
        ];
    }

    public function businessOwnerMfaSatisfied(Tenant $tenant, string $userId): bool
    {
        $wasInitialized = tenancy()->initialized;
        $previous = $wasInitialized ? tenancy()->tenant : null;

        try {
            if (! $wasInitialized || tenancy()->tenant?->id !== $tenant->id) {
                tenancy()->initialize($tenant);
            }

            $user = TenantUser::query()->find($userId);
            if (! $user) {
                return false;
            }

            return $this->mfaService->userHasConfirmedMfa($user);
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previous && $previous->id !== $tenant->id) {
                tenancy()->initialize($previous);
            }
        }
    }
}
