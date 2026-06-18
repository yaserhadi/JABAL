<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Models\Membership;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3C: Tenant member management (list, role assignment, suspend/activate).
 * Scope: existing tenant members only. No invite, remove, transfer ownership.
 */
class TenantMemberController extends Controller
{
    protected const ALLOWED_ROLES = ['tenant-admin', 'member'];

    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $memberships = Membership::query()
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

        if ($request->expectsJson()) {
            return ApiResponse::success($members->toArray());
        }

        $tenantData = ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug];

        return Inertia::render('Members/Index', [
            'tenant' => $tenantData,
            'members' => $members,
        ]);
    }

    public function updateRole(Request $request, string $tenant, string $user): JsonResponse|RedirectResponse
    {
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

    public function suspend(Request $request, string $tenant, string $user): JsonResponse|RedirectResponse
    {
        return $this->setStatus($request, $user, 'suspended', 'tenant_member.suspended');
    }

    public function activate(Request $request, string $tenant, string $user): JsonResponse|RedirectResponse
    {
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

    /**
     * Ensure we do not leave the tenant without an effective owner.
     * Uses membership_type = owner (authoritative), not Spatie role.
     */
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
