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

/**
 * TokenController handles API authentication token generation.
 * 
 * This controller implements the API authentication endpoint that generates
 * Sanctum tokens with tenant-specific abilities.
 */
class TokenController extends Controller
{
    /**
     * @var UserService
     */
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
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_id' => 'sometimes|uuid',
        ]);

        // Find user by email
        $user = User::where('email', $request->email)->first();

        // Verify password
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Determine tenant
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            // Verify user has access to the specified tenant
            $tenant = $this->userService->getTenants($user)->firstWhere('id', $tenantId);
            if (! $tenant) {
                return ApiResponse::error(
                    'TENANT_ACCESS_DENIED',
                    'You do not have access to the specified tenant.',
                    [],
                    403
                );
            }
        } else {
            // Use personal tenant as default
            $tenant = $this->userService->getPersonalTenant($user);
            if (! $tenant) {
                return ApiResponse::error(
                    'PERSONAL_TENANT_NOT_FOUND',
                    'Personal tenant not found for user.',
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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Token revoked successfully');
    }
}
