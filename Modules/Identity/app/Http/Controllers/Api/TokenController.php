<?php

namespace Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser as TenantMembership;

/**
 * TokenController handles API authentication token generation.
 *
 * This controller implements the API authentication endpoint that generates
 * Sanctum tokens with tenant-specific abilities.
 */
class TokenController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Generate an API token for the user.
     *
     * POST /api/v1/auth/token
     *
     * Accepts: email, password, optional tenant_id
     * Returns: Sanctum token with tenant:{uuid} ability
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_id' => 'sometimes|uuid',
        ]);

        $user = User::withoutGlobalScope('tenant')
            ->where('email', $request->email)
            ->get()
            ->first(fn (User $candidate) => Hash::check($request->password, $candidate->password));

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Determine tenant
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            $hasAccess = TenantMembership::query()
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->exists();

            $tenant = $hasAccess ? Tenant::query()->find($tenantId) : null;

            if (! $tenant) {
                return ApiResponse::error(
                    'TENANT_ACCESS_DENIED',
                    'You do not have access to the specified tenant.',
                    [],
                    403
                );
            }
        } else {
            $tenant = $this->userService->getPersonalTenant($user)
                ?? $this->userService->getTenants($user)->first();
            if (! $tenant) {
                return ApiResponse::error(
                    'PERSONAL_TENANT_NOT_FOUND',
                    'No active tenant membership found for user.',
                    [],
                    404
                );
            }
            $tenantId = $tenant->id;
        }

        // Generate token with tenant ability
        $token = $user->createToken(
            'api-token',
            ["tenant:{$tenantId}"]
        )->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'tenant_id' => $tenantId,
        ], 'Token generated successfully');
    }

    /**
     * Revoke the current access token.
     *
     * DELETE /api/v1/auth/token
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Token revoked successfully');
    }
}
