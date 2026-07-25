<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Str;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-099 Scenario B: issue / cancel / resolve Workforce SSO enrollment invitations.
 */
final class WorkforceSsoEnrollmentInvitationService
{
    public function __construct(
        protected SsoConfigService $configService,
        protected SsoSecurityAudit $audit,
    ) {}

    public function invitationTtlDays(): int
    {
        return max(1, (int) config('identity.sso.enrollment_invitation_ttl_days', 7));
    }

    /**
     * @return array{invitation: WorkforceSsoEnrollmentInvitation, plainToken: string}
     */
    public function createInvitation(
        Tenant $tenant,
        TenantUser $issuerAdmin,
        TenantUser $intended,
        Membership $membership,
        string $deliveryEmail,
        string $tenantHost,
    ): array {
        if ((string) $intended->tenant_id !== (string) $tenant->id) {
            throw new SsoSecurityException('Intended user does not belong to tenant.');
        }

        if ((string) $issuerAdmin->tenant_id !== (string) $tenant->id) {
            throw new SsoSecurityException('Issuer does not belong to tenant.');
        }

        if ((string) $membership->tenant_id !== (string) $tenant->id
            || (string) $membership->user_id !== (string) $intended->id
            || $membership->status !== 'active') {
            throw new SsoSecurityException('Membership must be active for the intended user.');
        }

        $versionId = $this->configService->getActiveVersionId($tenant);
        if ($versionId === null) {
            throw new SsoSecurityException('Active IdP configuration version is required.');
        }

        $version = $this->configService->findVersionForTenant($tenant, $versionId);
        if ($version === null || ! $version->mayServeNewProductionLogin()) {
            throw new SsoSecurityException('IdP configuration version is not active for enrollment.');
        }

        $config = TenantSsoConfig::query()
            ->where('tenant_id', $tenant->id)
            ->first();
        if ($config === null) {
            throw new SsoSecurityException('SSO configuration is required.');
        }

        $plainToken = Str::random(64);
        $correlationId = (string) Str::uuid();
        $host = strtolower($tenantHost);

        $invitation = WorkforceSsoEnrollmentInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'intended_user_id' => $intended->id,
            'membership_id' => $membership->id,
            'sso_config_id' => $config->id,
            'sso_config_version_id' => $version->id,
            'tenant_host' => $host,
            'issued_by_user_id' => $issuerAdmin->id,
            'delivery_email' => strtolower(trim($deliveryEmail)),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays($this->invitationTtlDays()),
            'audit_correlation_id' => $correlationId,
        ]);

        $this->audit->record('sso.enrollment.invitation_issued', [
            'tenant_id' => (string) $tenant->id,
            'invitation_id' => (string) $invitation->id,
            'actor_user_id' => (string) $issuerAdmin->id,
            'correlation_id' => $correlationId,
            'idp_configuration_version_id' => (string) $version->id,
            'purpose' => WorkforceSsoEnrollmentInvitation::PURPOSE,
            'status' => 'pending',
        ]);

        return [
            'invitation' => $invitation,
            'plainToken' => $plainToken,
        ];
    }

    public function cancelInvitation(
        Tenant $tenant,
        WorkforceSsoEnrollmentInvitation $invitation,
        TenantUser $actor,
    ): WorkforceSsoEnrollmentInvitation {
        if ((string) $invitation->tenant_id !== (string) $tenant->id) {
            throw new SsoSecurityException('Invitation tenant mismatch.');
        }

        if (! $invitation->isPending()) {
            throw new SsoSecurityException('Invitation is not cancellable.');
        }

        $invitation->forceFill([
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record('sso.enrollment.invitation_cancelled', [
            'tenant_id' => (string) $tenant->id,
            'invitation_id' => (string) $invitation->id,
            'actor_user_id' => (string) $actor->id,
            'correlation_id' => (string) $invitation->audit_correlation_id,
            'status' => 'cancelled',
        ]);

        return $invitation->fresh();
    }

    public function findValidByToken(Tenant $tenant, string $plainToken, string $tenantHost): ?WorkforceSsoEnrollmentInvitation
    {
        if ($plainToken === '' || strlen($plainToken) !== 64) {
            return null;
        }

        $invitation = WorkforceSsoEnrollmentInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $invitation instanceof WorkforceSsoEnrollmentInvitation) {
            return null;
        }

        if (! $invitation->isPending()) {
            return null;
        }

        if (strtolower($invitation->tenant_host) !== strtolower($tenantHost)) {
            return null;
        }

        return $invitation;
    }

    public function assertActorMatchesInvitation(TenantUser $actor, WorkforceSsoEnrollmentInvitation $invitation): void
    {
        if ((string) $actor->id !== (string) $invitation->intended_user_id) {
            throw new SsoSecurityException('Authenticated actor does not match invitation target.');
        }

        if ((string) $actor->tenant_id !== (string) $invitation->tenant_id) {
            throw new SsoSecurityException('Authenticated actor tenant mismatch.');
        }
    }
}
