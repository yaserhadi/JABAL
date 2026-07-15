<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Services\MembershipService;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Services\TenantSettingsService;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant member management: list, roles, suspend/activate, invite, remove, transfer ownership.
 */
class TenantMemberController extends Controller
{
    protected const ALLOWED_ROLES = ['tenant-admin', 'member'];

    public function __construct(
        private TenantInvitationService $invitationService,
        private MembershipService $membershipService
    ) {}

    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $memberships = Membership::query()
            ->visible()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->get();

        $removedMemberships = Membership::query()
            ->removed()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->get();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $members = $memberships->map(function (Membership $membership) {
            $user = $membership->user;
            $roles = $user ? $user->getRoleNames()->toArray() : [];

            return [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'membership_type' => $membership->membership_type,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'roles' => $roles,
            ];
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $removedMembers = $removedMemberships->map(function (Membership $membership) {
            $user = $membership->user;

            return [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'membership_type' => $membership->membership_type,
                'status' => $membership->status,
                'removed_at' => $membership->removed_at?->toIso8601String(),
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
            ];
        });

        $memberRemovalMode = app(TenantSettingsService::class)->memberRemovalMode($tenant);

        $pendingInvitations = $this->invitationService->pendingForTenant($tenant)->map(fn ($inv) => [
            'id' => $inv->id,
            'email' => $inv->email,
            'role' => $inv->role,
            'expires_at' => $inv->expires_at?->toIso8601String(),
            'created_at' => $inv->created_at?->toIso8601String(),
        ]);

        $actorMembership = $this->actorMembership($tenant);

        if ($request->expectsJson()) {
            return ApiResponse::success([
                'members' => $members->toArray(),
                'removed_members' => $removedMembers->toArray(),
                'member_removal_mode' => $memberRemovalMode,
                'pending_invitations' => $pendingInvitations->toArray(),
                'actor_is_owner' => $actorMembership?->isOwner() ?? false,
            ]);
        }

        $tenantData = TenantInertiaProps::from($tenant);

        return Inertia::render('Members/Index', [
            'tenant' => $tenantData,
            'members' => $members,
            'removedMembers' => $removedMembers,
            'memberRemovalMode' => $memberRemovalMode,
            'pendingInvitations' => $pendingInvitations,
            'actorIsOwner' => $actorMembership?->isOwner() ?? false,
        ]);
    }

    public function invite(Request $request): JsonResponse|RedirectResponse
    {
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', TenantInvitationService::ALLOWED_ROLES)],
        ]);

        $role = $validated['role'] ?? 'member';
        if ($role === 'tenant-admin') {
            $actorMembership = $this->actorMembership($tenantModel);
            if (! $actorMembership?->isOwner()) {
                throw ValidationException::withMessages([
                    'role' => ['Only the tenant owner may promote members to tenant-admin.'],
                ]);
            }
        }

        try {
            $result = $this->invitationService->createInvitation(
                $tenantModel,
                $validated['email'],
                auth()->user(),
                $role
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        $payload = [
            'invitation_id' => $result['invitation']->id,
            'email' => $result['invitation']->email,
            'accept_url' => $result['acceptUrl'],
            'expires_at' => $result['invitation']->expires_at->toIso8601String(),
        ];

        if ($request->expectsJson()) {
            return ApiResponse::success($payload);
        }

        return back()->with([
            'success' => 'Invitation sent — email delivered; you can also copy the link below.',
            'inviteUrl' => $result['acceptUrl'],
        ]);
    }

    public function resendInvitation(Request $request, string $invitation): JsonResponse|RedirectResponse
    {
        $invitation = (string) $request->route('invitation');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $invitationModel = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantModel->id)
            ->where('id', $invitation)
            ->pending()
            ->first();

        if (! $invitationModel) {
            abort(404);
        }

        try {
            $result = $this->invitationService->reissueInvitation($invitationModel, auth()->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invitation' => [$e->getMessage()]]);
        }

        $payload = [
            'invitation_id' => $result['invitation']->id,
            'email' => $result['invitation']->email,
            'accept_url' => $result['acceptUrl'],
            'expires_at' => $result['invitation']->expires_at->toIso8601String(),
        ];

        if ($request->expectsJson()) {
            return ApiResponse::success($payload);
        }

        return back()->with('success', 'Invitation email resent.');
    }

    public function remove(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $targetUser = $this->resolveApplicationUser($user);
        $membership = Membership::query()
            ->where('tenant_id', $tenantModel->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if (! $membership) {
            abort(404);
        }

        try {
            $membershipId = $membership->id;
            $removalMode = $this->membershipService->remove($membership, $tenantModel);

            app(AuditLoggerInterface::class)->log('tenant_member.removed', [
                'auditable_type' => Membership::class,
                'auditable_id' => $membershipId,
                'old_values' => [
                    'user_id' => $targetUser->id,
                    'tenant_id' => $tenantModel->id,
                ],
                'new_values' => [
                    'user_id' => $targetUser->id,
                    'tenant_id' => $tenantModel->id,
                    'removal_mode' => $removalMode,
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['user' => [$e->getMessage()]]);
        }

        if ($request->expectsJson()) {
            return ApiResponse::success(['removed' => true]);
        }

        return back()->with('success', 'Member removed successfully.');
    }

    public function restore(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $targetUser = $this->resolveApplicationUser($user);
        $membership = Membership::query()
            ->removed()
            ->where('tenant_id', $tenantModel->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if (! $membership) {
            abort(404);
        }

        $previousRemovedAt = $membership->removed_at?->toIso8601String();

        try {
            $this->membershipService->restore($membership, $tenantModel);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['user' => [$e->getMessage()]]);
        }

        app(AuditLoggerInterface::class)->log('tenant_member.restored', [
            'auditable_type' => Membership::class,
            'auditable_id' => $membership->id,
            'new_values' => [
                'user_id' => $targetUser->id,
                'tenant_id' => $tenantModel->id,
                'restored_by' => auth()->id(),
                'previous_removed_at' => $previousRemovedAt,
            ],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['restored' => true]);
        }

        return back()->with('success', 'Member restored successfully.');
    }

    public function deleteForever(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $targetUser = $this->resolveApplicationUser($user);
        $membership = Membership::query()
            ->removed()
            ->where('tenant_id', $tenantModel->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if (! $membership) {
            abort(404);
        }

        $membershipId = $membership->id;

        try {
            $this->membershipService->deleteForever($membership, $tenantModel);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['user' => [$e->getMessage()]]);
        }

        app(AuditLoggerInterface::class)->log('tenant_member.permanently_deleted', [
            'auditable_type' => Membership::class,
            'auditable_id' => $membershipId,
            'old_values' => [
                'user_id' => $targetUser->id,
                'tenant_id' => $tenantModel->id,
            ],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['deleted' => true]);
        }

        return back()->with('success', 'Member permanently deleted.');
    }

    public function revokeInvitation(Request $request, string $invitation): JsonResponse|RedirectResponse
    {
        $invitation = (string) $request->route('invitation');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $invitationModel = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantModel->id)
            ->where('id', $invitation)
            ->pending()
            ->first();

        if (! $invitationModel) {
            abort(404);
        }

        try {
            $this->invitationService->revokeInvitation($invitationModel);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invitation' => [$e->getMessage()]]);
        }

        app(AuditLoggerInterface::class)->log('tenant_member.invitation_revoked', [
            'auditable_type' => TenantInvitation::class,
            'auditable_id' => $invitationModel->id,
            'old_values' => [
                'email' => $invitationModel->email,
                'tenant_id' => $tenantModel->id,
            ],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['revoked' => true]);
        }

        return back()->with('success', 'Invitation revoked.');
    }

    public function transferOwnership(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $actorMembership = $this->actorMembership($tenantModel);
        if (! $actorMembership?->isOwner()) {
            abort(403, 'Only the tenant owner may transfer ownership.');
        }

        $fromUser = auth()->user();
        $toUser = $this->resolveApplicationUser($user);

        try {
            $this->membershipService->transferOwnership($tenantModel, $fromUser, $toUser);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['user' => [$e->getMessage()]]);
        }

        app(AuditLoggerInterface::class)->log('tenant_member.ownership_transferred', [
            'auditable_type' => Membership::class,
            'new_values' => [
                'from_user_id' => $fromUser->id,
                'to_user_id' => $toUser->id,
                'tenant_id' => $tenantModel->id,
            ],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['transferred' => true]);
        }

        return back()->with('success', 'Ownership transferred successfully.');
    }

    public function updateRole(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $user = $this->resolveApplicationUser($user);

        $membership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();
        if (! $membership) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_ROLES)],
        ]);
        $newRole = $validated['role'];

        $actorMembership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', auth()->id())
            ->first();
        if (! $actorMembership) {
            abort(403);
        }

        if ($newRole === 'tenant-admin' && ! $actorMembership->isOwner()) {
            throw ValidationException::withMessages([
                'role' => ['Only the tenant owner may promote members to tenant-admin.'],
            ]);
        }

        $this->ensureLastOwnerProtection($tenant, $membership, null, $newRole);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $oldRoles = $user->getRoleNames()->toArray();
        $user->syncRoles([$newRole]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        app(AuditLoggerInterface::class)->log('tenant_member.role_changed', [
            'auditable_type' => Membership::class,
            'auditable_id' => $membership->id,
            'old_values' => ['roles' => $oldRoles],
            'new_values' => ['roles' => [$newRole]],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['role' => $newRole]);
        }

        return back()->with('success', 'Role updated successfully.');
    }

    public function suspend(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');

        return $this->setStatus($request, $user, 'suspended', 'tenant_member.suspended');
    }

    public function activate(Request $request, string $user): JsonResponse|RedirectResponse
    {
        $user = (string) $request->route('user');

        return $this->setStatus($request, $user, 'active', 'tenant_member.activated');
    }

    protected function setStatus(Request $request, string $userId, string $status, string $auditEvent): JsonResponse|RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $user = $this->resolveApplicationUser($userId);

        $membership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();
        if (! $membership) {
            abort(404);
        }

        $this->ensureLastOwnerProtection($tenant, $membership, $status, null);

        $oldStatus = $membership->status;
        $membership->status = $status;
        $membership->save();

        app(AuditLoggerInterface::class)->log($auditEvent, [
            'auditable_type' => Membership::class,
            'auditable_id' => $membership->id,
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $status],
        ]);

        if ($request->expectsJson()) {
            return ApiResponse::success(['status' => $status]);
        }

        return back()->with('success', 'Member status updated successfully.');
    }

    protected function actorMembership(\Modules\Tenancy\Models\Tenant $tenant): ?Membership
    {
        return Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', auth()->id())
            ->first();
    }

    protected function ensureLastOwnerProtection(
        \Modules\Tenancy\Models\Tenant $tenant,
        Membership $membership,
        ?string $newStatus,
        ?string $newRole
    ): void {
        $ownerCount = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('membership_type', 'owner')
            ->where('status', 'active')
            ->count();

        if ($ownerCount <= 1 && $membership->isOwner() && $membership->status === 'active') {
            if ($newStatus === 'suspended') {
                throw ValidationException::withMessages([
                    'status' => ['Cannot suspend the last owner of the tenant.'],
                ]);
            }
            if ($newRole === 'member') {
                throw ValidationException::withMessages([
                    'role' => ['Cannot remove admin authority from the last owner.'],
                ]);
            }
        }
    }

    protected function resolveApplicationUser(string $userId): User
    {
        return User::withoutGlobalScope('tenant')->findOrFail($userId);
    }
}
