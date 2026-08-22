<?php

namespace Modules\Identity\Services;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Identity\Mail\TenantInvitationMail;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

class TenantInvitationService
{
    public const ALLOWED_ROLES = ['member', 'tenant-admin'];

    /**
     * WAVE-3 GAP-004: J2 Invite TTL from config (hours). Do not hard-code.
     */
    public function invitationTtlHours(): int
    {
        return max(1, (int) config('tenancy.invitation_ttl_hours', 24));
    }

    /**
     * WAVE-3 GAP-004: Admin creates User before Invite (immutable UUID exists).
     * Does not create Membership or Roles — Invite acceptance does that.
     */
    public function createUser(
        Tenant $tenant,
        string $firstName,
        string $lastName,
        string $email
    ): TenantUser {
        $email = strtolower(trim($email));
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $name = trim($firstName.' '.$lastName);

        if ($firstName === '' || $lastName === '' || $name === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name and last name are required.'],
            ]);
        }

        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $existing = TenantUser::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'email' => ['A user with this email already exists in this organization.'],
                ]);
            }

            // Unusable password until account-completion Invite sets a User-owned Password.
            // Model casts password as hashed — pass plain random string (do not pre-hash).
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => Str::password(64),
            ]);

            app(AuditLoggerInterface::class)->log('tenant_user.created', [
                'tenant_id' => $tenant->id,
                'auditable_type' => TenantUser::class,
                'auditable_id' => $user->id,
                'new_values' => [
                    'user_id' => $user->id,
                    'email' => $email,
                    'name' => $name,
                ],
            ]);

            return $user;
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * WAVE-3 GAP-004: Invite an existing User (Invite ≠ create User).
     *
     * @return array{invitation: TenantInvitation, plainToken: string, acceptUrl: string}
     */
    public function createInvitation(
        Tenant $tenant,
        TenantUser $intendedUser,
        TenantUser $invitedBy,
        string $role = 'member'
    ): array {
        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid invitation role.');
        }

        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            if ((string) $intendedUser->tenant_id !== (string) $tenant->id) {
                throw ValidationException::withMessages([
                    'user_id' => ['Invited user does not belong to this organization.'],
                ]);
            }

            $email = strtolower(trim((string) $intendedUser->email));

            if ($role === 'tenant-admin') {
                $inviterMembership = Membership::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $invitedBy->id)
                    ->first();

                if (! $inviterMembership?->isOwner()) {
                    throw ValidationException::withMessages([
                        'role' => ['Only the tenant owner may promote members to tenant-admin.'],
                    ]);
                }
            }

            $this->assertInvitableEmail($tenant, $email);

            app(MembershipService::class)->assertSeatCapacityForInvitation($tenant->id);

            $plainToken = Str::random(64);
            $expiresAt = now()->addHours($this->invitationTtlHours());
            $acceptUrl = url('/invitations/'.$plainToken);

            $invitation = DB::connection('tenant')->transaction(function () use (
                $tenant,
                $email,
                $intendedUser,
                $invitedBy,
                $role,
                $plainToken,
                $expiresAt,
                $acceptUrl
            ) {
                $record = TenantInvitation::query()->create([
                    'tenant_id' => $tenant->id,
                    'email' => $email,
                    'intended_user_id' => $intendedUser->id,
                    'invited_by_user_id' => $invitedBy->id,
                    'token_hash' => hash('sha256', $plainToken),
                    'role' => $role,
                    'expires_at' => $expiresAt,
                ]);

                $this->sendInvitationEmail($tenant, $invitedBy, $email, $role, $acceptUrl, $expiresAt);

                return $record;
            });

            app(AuditLoggerInterface::class)->log('tenant_member.invited', [
                'tenant_id' => $tenant->id,
                'auditable_type' => TenantInvitation::class,
                'auditable_id' => $invitation->id,
                'new_values' => [
                    'email' => $email,
                    'intended_user_id' => $intendedUser->id,
                    'tenant_id' => $tenant->id,
                    'invited_by_user_id' => $invitedBy->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);

            return [
                'invitation' => $invitation,
                'plainToken' => $plainToken,
                'acceptUrl' => $acceptUrl,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * Convenience: create User then issue J2 Invite (same transaction boundary for admin UX).
     *
     * @return array{user: TenantUser, invitation: TenantInvitation, plainToken: string, acceptUrl: string}
     */
    public function createUserAndInvite(
        Tenant $tenant,
        string $firstName,
        string $lastName,
        string $email,
        TenantUser $invitedBy,
        string $role = 'member'
    ): array {
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return DB::connection('tenant')->transaction(function () use (
                $tenant,
                $firstName,
                $lastName,
                $email,
                $invitedBy,
                $role
            ) {
                $user = $this->createUser($tenant, $firstName, $lastName, $email);
                $invite = $this->createInvitation($tenant, $user, $invitedBy, $role);

                return [
                    'user' => $user,
                    'invitation' => $invite['invitation'],
                    'plainToken' => $invite['plainToken'],
                    'acceptUrl' => $invite['acceptUrl'],
                ];
            });
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    public function findValidByToken(string $plainToken): ?TenantInvitation
    {
        $hash = hash('sha256', $plainToken);

        return TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('token_hash', $hash)
            ->pending()
            ->first();
    }

    public function acceptInvitation(string $plainToken, TenantUser $acceptingUser): Membership
    {
        $invitation = $this->findValidByToken($plainToken);
        if (! $invitation) {
            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid or has expired.'],
            ]);
        }

        return $this->acceptInvitationRecord($invitation, $acceptingUser);
    }

    public function acceptInvitationRecord(TenantInvitation $invitation, TenantUser $acceptingUser): Membership
    {
        $this->assertInvitationBoundToUser($invitation);
        $this->assertInvitationStillPending($invitation);

        if (strtolower($acceptingUser->email) !== strtolower($invitation->email)) {
            throw ValidationException::withMessages([
                'email' => ['Your account email does not match this invitation.'],
            ]);
        }

        if ((string) $acceptingUser->id !== (string) $invitation->intended_user_id) {
            throw ValidationException::withMessages([
                'email' => ['This invitation is bound to a different user account.'],
            ]);
        }

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        tenancy()->initialize($tenant);

        try {
            return DB::connection('tenant')->transaction(function () use ($invitation, $tenant, $acceptingUser) {
                return $this->completeInvitation($invitation, $tenant, $acceptingUser);
            });
        } finally {
            tenancy()->end();
        }
    }

    /**
     * WAVE-3 GAP-004: Complete account for the existing intended User.
     * MUST NOT create a User from invitation email.
     *
     * @return array{user: TenantUser, membership: Membership, tenant: Tenant}
     */
    public function completeAccountInvitation(TenantInvitation $invitation, string $password): array
    {
        $this->assertInvitationBoundToUser($invitation);
        $this->assertInvitationStillPending($invitation);

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        tenancy()->initialize($tenant);

        try {
            return DB::connection('tenant')->transaction(function () use ($invitation, $tenant, $password) {
                $user = TenantUser::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($invitation->intended_user_id)
                    ->lockForUpdate()
                    ->first();

                if (! $user) {
                    throw ValidationException::withMessages([
                        'token' => ['This invitation is invalid or has expired.'],
                    ]);
                }

                if (strtolower((string) $user->email) !== strtolower((string) $invitation->email)) {
                    throw ValidationException::withMessages([
                        'email' => ['Invitation email does not match the intended user.'],
                    ]);
                }

                $user->forceFill([
                    'password' => $password,
                ])->save();

                $membership = $this->completeInvitation($invitation, $tenant, $user);

                app(AuditLoggerInterface::class)->log('tenant_member.account_completed', [
                    'tenant_id' => $tenant->id,
                    'auditable_type' => TenantInvitation::class,
                    'auditable_id' => $invitation->id,
                    'new_values' => [
                        'user_id' => $user->id,
                        'tenant_id' => $tenant->id,
                        'membership_id' => $membership->id,
                    ],
                ]);

                return [
                    'user' => $user->fresh(),
                    'membership' => $membership,
                    'tenant' => $tenant,
                ];
            });
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @deprecated WAVE-3: Invite must not create User. Prefer completeAccountInvitation.
     */
    public function registerAndAccept(string $plainToken, string $name, string $password): array
    {
        $invitation = $this->findValidByToken($plainToken);
        if (! $invitation) {
            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid or has expired.'],
            ]);
        }

        return $this->completeAccountInvitation($invitation, $password);
    }

    /**
     * @deprecated WAVE-3: Invite must not create User. Prefer completeAccountInvitation.
     */
    public function registerAndAcceptInvitation(TenantInvitation $invitation, string $name, string $password): array
    {
        return $this->completeAccountInvitation($invitation, $password);
    }

    public function reissueInvitation(TenantInvitation $invitation, TenantUser $actor): array
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => ['Only pending invitations can be resent.'],
            ]);
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invitation' => ['This invitation has expired.'],
            ]);
        }

        $this->assertInvitationBoundToUser($invitation);

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $this->assertResendEligible($tenant, $invitation);

            $plainToken = Str::random(64);
            $expiresAt = now()->addHours($this->invitationTtlHours());
            $acceptUrl = url('/invitations/'.$plainToken);
            $previousTokenHash = $invitation->token_hash;

            $invitation = DB::connection('tenant')->transaction(function () use (
                $invitation,
                $tenant,
                $actor,
                $plainToken,
                $expiresAt,
                $acceptUrl
            ) {
                $invitation->refresh();

                $invitation->update([
                    'token_hash' => hash('sha256', $plainToken),
                    'expires_at' => $expiresAt,
                ]);

                $this->sendInvitationEmail(
                    $tenant,
                    $actor,
                    $invitation->email,
                    $invitation->role,
                    $acceptUrl,
                    $expiresAt
                );

                return $invitation->fresh();
            });

            app(AuditLoggerInterface::class)->log('tenant_member.invitation_reissued', [
                'tenant_id' => $tenant->id,
                'auditable_type' => TenantInvitation::class,
                'auditable_id' => $invitation->id,
                'old_values' => [
                    'token_hash' => $previousTokenHash,
                ],
                'new_values' => [
                    'email' => $invitation->email,
                    'intended_user_id' => $invitation->intended_user_id,
                    'tenant_id' => $tenant->id,
                    'reissued_by_user_id' => $actor->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);

            return [
                'invitation' => $invitation,
                'plainToken' => $plainToken,
                'acceptUrl' => $acceptUrl,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    public function revokeInvitation(TenantInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw new InvalidArgumentException('Cannot revoke an accepted invitation.');
        }

        $invitation->update(['revoked_at' => now()]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TenantInvitation>
     */
    public function pendingForTenant(Tenant $tenant)
    {
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return TenantInvitation::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->pending()
                ->orderByDesc('created_at')
                ->get();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    protected function assertInvitationBoundToUser(TenantInvitation $invitation): void
    {
        if ($invitation->intended_user_id === null || $invitation->intended_user_id === '') {
            app(AuditLoggerInterface::class)->log('tenant_member.invitation_rejected', [
                'tenant_id' => $invitation->tenant_id,
                'auditable_type' => TenantInvitation::class,
                'auditable_id' => $invitation->id,
                'new_values' => [
                    'reason' => 'missing_intended_user_id',
                ],
            ]);

            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid or has expired.'],
            ]);
        }
    }

    protected function assertInvitationStillPending(TenantInvitation $invitation): void
    {
        $invitation->refresh();

        if (! $invitation->isPending()) {
            app(AuditLoggerInterface::class)->log('tenant_member.invitation_rejected', [
                'tenant_id' => $invitation->tenant_id,
                'auditable_type' => TenantInvitation::class,
                'auditable_id' => $invitation->id,
                'new_values' => [
                    'reason' => 'not_pending',
                ],
            ]);

            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid or has expired.'],
            ]);
        }
    }

    protected function sendInvitationEmail(
        Tenant $tenant,
        TenantUser $invitedBy,
        string $email,
        string $role,
        string $acceptUrl,
        \DateTimeInterface $expiresAt
    ): void {
        Mail::to($email)->send(new TenantInvitationMail(
            tenant: $tenant,
            inviterName: $invitedBy->name,
            inviteeEmail: $email,
            role: $role,
            acceptUrl: $acceptUrl,
            expiresAt: $expiresAt,
        ));
    }

    protected function assertInvitableEmail(Tenant $tenant, string $email): void
    {
        $duplicatePending = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->pending()
            ->exists();

        if ($duplicatePending) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already exists for this email.'],
            ]);
        }

        $membership = $this->findMembershipForEmail($tenant, $email);

        if (! $membership) {
            return;
        }

        if ($membership->status === 'active') {
            throw ValidationException::withMessages([
                'email' => ['This user is already an active member of the tenant.'],
            ]);
        }

        if ($membership->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['This email already belongs to a suspended member. Use Activate from the Active members tab to restore access.'],
            ]);
        }

        if ($membership->status === 'removed') {
            throw ValidationException::withMessages([
                'email' => ['This email belongs to a removed member. Restore them from the Removed members tab, or delete the record permanently to invite again.'],
            ]);
        }

        throw ValidationException::withMessages([
            'email' => ['This email is already associated with a membership record. Manage the member from the Active members tab.'],
        ]);
    }

    protected function assertResendEligible(Tenant $tenant, TenantInvitation $invitation): void
    {
        $membership = $this->findMembershipForEmail($tenant, strtolower(trim($invitation->email)));

        if (! $membership) {
            return;
        }

        if ($membership->status === 'active') {
            throw ValidationException::withMessages([
                'email' => ['This user is already an active member of the tenant.'],
            ]);
        }

        if ($membership->status === 'suspended') {
            throw ValidationException::withMessages([
                'email' => ['This email already belongs to a suspended member. Use Activate from the Active members tab to restore access.'],
            ]);
        }

        if ($membership->status === 'removed') {
            throw ValidationException::withMessages([
                'email' => ['This email belongs to a removed member. Restore them from the Removed members tab, or delete the record permanently to invite again.'],
            ]);
        }
    }

    protected function findMembershipForEmail(Tenant $tenant, string $email): ?Membership
    {
        $userIds = TenantUser::withoutGlobalScope('tenant')
            ->where('email', $email)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return null;
        }

        return Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $userIds)
            ->first();
    }

    protected function completeInvitation(
        TenantInvitation $invitation,
        Tenant $tenant,
        TenantUser $user
    ): Membership {
        $membership = app(MembershipService::class)->create(
            $user->id,
            $tenant->id,
            'member',
            'active',
            skipSeatCheck: true
        );

        $provisioner = app(\Modules\Tenancy\Services\TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        try {
            $role = \App\Models\Rbac\TenantRole::query()
                ->where('name', $invitation->role)
                ->where('tenant_id', $tenant->id)
                ->where('guard_name', config('auth.defaults.guard'))
                ->first();

            if (! $role) {
                throw new InvalidArgumentException('Invitation role is not provisioned for this tenant.');
            }

            $user->syncRoles([$role]);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }

        $invitation->update(['accepted_at' => now()]);

        app(AuditLoggerInterface::class)->log('tenant_member.invitation_accepted', [
            'tenant_id' => $tenant->id,
            'auditable_type' => TenantInvitation::class,
            'auditable_id' => $invitation->id,
            'new_values' => [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'membership_id' => $membership->id,
            ],
        ]);

        return $membership;
    }
}
