<?php

namespace Modules\Identity\Services;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
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
            $expiresAt = now()->addDays((int) config('tenancy.invitation_ttl_days', 7));

            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'invited_by_user_id' => $invitedBy->id,
                'token_hash' => hash('sha256', $plainToken),
                'role' => $role,
                'expires_at' => $expiresAt,
            ]);

            $acceptUrl = url('/invitations/'.$plainToken);

            app(AuditLoggerInterface::class)->log('tenant_member.invited', [
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
