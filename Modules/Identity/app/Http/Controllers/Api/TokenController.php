<?php

namespace Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Identity\Models\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Exceptions\ApiTokenException;
use Modules\Identity\Http\Requests\StoreApiTokenRequest;
use Modules\Identity\Services\ApiTokenService;

class TokenController extends Controller
{
    public function __construct(
        protected ApiTokenService $apiTokenService,
    ) {}

    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        try {
            $expiresAt = $request->filled('expires_at')
                ? $request->date('expires_at')
                : null;

            $result = $this->apiTokenService->issueToken(
                email: $request->string('email')->toString(),
                password: $request->string('password')->toString(),
                tenantId: $request->input('tenant_id'),
                name: $request->input('name'),
                mfaCode: $request->input('mfa_code'),
                expiresAt: $expiresAt,
            );

            $request->clearRateLimit();

            return ApiResponse::success($result, 'Token generated successfully');
        } catch (ValidationException $e) {
            $request->recordFailedAttempt();
            throw $e;
        } catch (ApiTokenException $e) {
            $request->recordFailedAttempt();

            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->httpStatus);
        }
    }

    public function index(Request $request): JsonResponse
    {
        /** @var TenantUser $user */
        $user = $request->user();
        $tenantId = (string) $request->header('X-Tenant-Id');

        $tokens = $this->apiTokenService->listTokensForTenant($user, $tenantId);

        return ApiResponse::success([
            'tokens' => $this->apiTokenService->formatTokenList($tokens),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var TenantUser $user */
        $user = $request->user();
        $tenantId = (string) $request->header('X-Tenant-Id');

        try {
            $this->apiTokenService->revokeCurrentToken($user, $tenantId);
        } catch (ApiTokenException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->httpStatus);
        }

        return ApiResponse::success(null, 'Token revoked successfully');
    }

    public function destroyById(Request $request, int|string $tokenId): JsonResponse
    {
        /** @var TenantUser $user */
        $user = $request->user();
        $tenantId = (string) $request->header('X-Tenant-Id');

        try {
            $this->apiTokenService->revokeTokenById($user, $tenantId, $tokenId);
        } catch (ApiTokenException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->details, $e->httpStatus);
        }

        return ApiResponse::success(null, 'Token revoked successfully');
    }
}
