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

    public function createInvitation(
        Tenant $tenant,
        string $email,
        TenantUser $invitedBy,
        string $role = 'member'
    ): array {
        $email = strtolower(trim($email));

        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException('Invalid invitation role.');
        }

        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
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

            app(MembershipService::class)->assertSeatCapacityForInvitation($tenant->id);

            $userIds = TenantUser::withoutGlobalScope('tenant')
                ->where('email', $email)
                ->pluck('id');

            $existingMember = $userIds->isNotEmpty() && Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereIn('user_id', $userIds)
                ->where('status', 'active')
                ->exists();

            if ($existingMember) {
                throw ValidationException::withMessages([
                    'email' => ['This user is already an active member of the tenant.'],
                ]);
            }

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

            $plainToken = Str::random(64);
            $expiresAt = now()->addDays((int) config('tenancy.invitation_ttl_days'));
            $acceptUrl = url('/invitations/'.$plainToken);

            $invitation = DB::connection('tenant')->transaction(function () use (
                $tenant,
                $email,
                $invitedBy,
                $role,
                $plainToken,
                $expiresAt,
                $acceptUrl
            ) {
                $record = TenantInvitation::query()->create([
                    'tenant_id' => $tenant->id,
                    'email' => $email,
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
        if (strtolower($acceptingUser->email) !== strtolower($invitation->email)) {
            throw ValidationException::withMessages([
                'email' => ['Your account email does not match this invitation.'],
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

    public function registerAndAccept(string $plainToken, string $name, string $password): array
    {
        $invitation = $this->findValidByToken($plainToken);
        if (! $invitation) {
            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid or has expired.'],
            ]);
        }

        return $this->registerAndAcceptInvitation($invitation, $name, $password);
    }

    public function registerAndAcceptInvitation(TenantInvitation $invitation, string $name, string $password): array
    {
        $existing = TenantUser::withoutGlobalScope('tenant')
            ->where('email', $invitation->email)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['An account with this email already exists. Please log in to accept the invitation.'],
            ]);
        }

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        tenancy()->initialize($tenant);

        try {
            return DB::connection('tenant')->transaction(function () use ($invitation, $tenant, $name, $password) {
                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => $password,
                ]);

                $membership = $this->completeInvitation($invitation, $tenant, $user);

                return [
                    'user' => $user,
                    'membership' => $membership,
                    'tenant' => $tenant,
                ];
            });
        } finally {
            tenancy()->end();
        }
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

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);
        $wasInitialized = tenancy()->initialized;

        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $plainToken = Str::random(64);
            $expiresAt = now()->addDays((int) config('tenancy.invitation_ttl_days'));
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
