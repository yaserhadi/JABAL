<?php

namespace Modules\Identity\Services;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-099 Scenario B: create TenantUserIdentity from invitation + local actor + enrollment continuation.
 *
 * MUST NOT Auth::login, regenerate session, or mutate membership/roles.
 */
final class WorkforceSsoEnrollmentAssociationService
{
    public function __construct(
        protected AuthenticationTransactionService $transactions,
        protected WorkforceSsoEnrollmentInvitationService $invitations,
        protected SsoSecurityAudit $audit,
    ) {}

    /**
     * @param  array{
     *   invitation: WorkforceSsoEnrollmentInvitation,
     *   authenticatedLocalActor: TenantUser,
     *   continuationReference: string,
     *   browserBinding: ?string,
     *   requestHost: string,
     *   email_at_link?: ?string,
     * }  $input
     * @return array{identity: TenantUserIdentity, created: bool}
     */
    public function associateFromWorkforceEnrollmentInvitation(array $input): array
    {
        /** @var WorkforceSsoEnrollmentInvitation $invitation */
        $invitation = $input['invitation'];
        /** @var TenantUser $actor */
        $actor = $input['authenticatedLocalActor'];
        $reference = (string) $input['continuationReference'];
        $browserBinding = $input['browserBinding'] ?? null;
        $requestHost = strtolower((string) $input['requestHost']);
        $emailAtLink = $input['email_at_link'] ?? null;

        $this->invitations->assertActorMatchesInvitation($actor, $invitation);

        if (! $invitation->isPending()) {
            throw new SsoSecurityException('Invitation is not pending.');
        }

        if ($requestHost !== strtolower($invitation->tenant_host)) {
            throw new SsoSecurityException('Invitation host mismatch.');
        }

        $tenant = Tenant::query()->find($invitation->tenant_id);
        if (! $tenant instanceof Tenant || $tenant->status !== 'active') {
            throw new SsoSecurityException('Tenant is not active.');
        }

        $membership = Membership::query()
            ->whereKey($invitation->membership_id)
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $invitation->intended_user_id)
            ->where('status', 'active')
            ->first();

        if (! $membership instanceof Membership) {
            throw new SsoSecurityException('Membership is not active.');
        }

        // Peek before irreversible consume so invitation/actor failures do not burn evidence.
        $peek = $this->transactions->findEnrollmentContinuationByReference($reference);
        if ($peek === null) {
            throw new SsoSecurityException('Enrollment continuation is invalid.');
        }
        if ((string) $peek->invitation_id !== (string) $invitation->id
            || (string) $peek->intended_user_id !== (string) $invitation->intended_user_id
            || (string) $peek->idp_configuration_version_id !== (string) $invitation->sso_config_version_id
            || (string) $peek->tenant_id !== (string) $tenant->id
            || strtolower($peek->destination_host) !== $requestHost) {
            throw new SsoSecurityException('Continuation binding mismatch.');
        }

        return DB::connection('tenant')->transaction(function () use (
            $tenant,
            $invitation,
            $actor,
            $reference,
            $browserBinding,
            $requestHost,
            $emailAtLink,
        ) {
            $lockedInvitation = WorkforceSsoEnrollmentInvitation::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvitation instanceof WorkforceSsoEnrollmentInvitation || ! $lockedInvitation->isPending()) {
                throw new SsoSecurityException('Invitation is not pending.');
            }

            $consumed = $this->transactions->consumeEnrollmentContinuation(
                $reference,
                (string) $tenant->id,
                $requestHost,
                is_string($browserBinding) ? $browserBinding : null,
            );

            if ($consumed === null) {
                throw new SsoSecurityException('Enrollment continuation is invalid.');
            }

            $issuer = rtrim(trim($consumed['issuer']), '/');
            $subject = $consumed['subject'];

            $existing = TenantUserIdentity::query()
                ->where('tenant_id', $tenant->id)
                ->where('issuer', $issuer)
                ->where('subject', $subject)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof TenantUserIdentity) {
                if ((string) $existing->user_id !== (string) $actor->id) {
                    throw new SsoSecurityException('Identity already linked to another user.');
                }

                $this->consumeInvitation($lockedInvitation);

                $this->audit->record('sso.enrollment.associated', [
                    'tenant_id' => (string) $tenant->id,
                    'invitation_id' => (string) $lockedInvitation->id,
                    'actor_user_id' => (string) $actor->id,
                    'identity_link_id' => (string) $existing->id,
                    'enrollment_continuation_id' => (string) $consumed['continuation']->id,
                    'idp_configuration_version_id' => (string) $lockedInvitation->sso_config_version_id,
                    'correlation_id' => (string) $lockedInvitation->audit_correlation_id,
                    'status' => 'idempotent',
                    'purpose' => WorkforceSsoEnrollmentInvitation::PURPOSE,
                ]);

                return [
                    'identity' => $existing,
                    'created' => false,
                ];
            }

            $identity = TenantUserIdentity::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $actor->id,
                'issuer' => $issuer,
                'subject' => $subject,
                'email_at_link' => is_string($emailAtLink) && $emailAtLink !== '' ? $emailAtLink : null,
            ]);

            $this->consumeInvitation($lockedInvitation);

            $this->audit->record('sso.enrollment.associated', [
                'tenant_id' => (string) $tenant->id,
                'invitation_id' => (string) $lockedInvitation->id,
                'actor_user_id' => (string) $actor->id,
                'identity_link_id' => (string) $identity->id,
                'enrollment_continuation_id' => (string) $consumed['continuation']->id,
                'idp_configuration_version_id' => (string) $lockedInvitation->sso_config_version_id,
                'correlation_id' => (string) $lockedInvitation->audit_correlation_id,
                'status' => 'created',
                'purpose' => WorkforceSsoEnrollmentInvitation::PURPOSE,
            ]);

            return [
                'identity' => $identity,
                'created' => true,
            ];
        });
    }

    protected function consumeInvitation(WorkforceSsoEnrollmentInvitation $invitation): void
    {
        $updated = WorkforceSsoEnrollmentInvitation::query()
            ->whereKey($invitation->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new SsoSecurityException('Invitation could not be consumed.');
        }
    }
}
