<?php

namespace Modules\Identity\Services;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Identity\Exceptions\ApiTokenException;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * Personal access token lifecycle (DEC-0014 / BK-021).
 */
class ApiTokenService
{
    public function __construct(
        protected UserService $userService,
        protected MembershipService $membershipService,
        protected MfaService $mfaService,
    ) {}

    /**
     * @return array{token: string, token_type: string, user: array{id: string, name: string, email: string}, tenant_id: string}
     *
     * @throws ValidationException
     * @throws ApiTokenException
     */
    public function issueToken(
        string $email,
        string $password,
        ?string $tenantId = null,
        ?string $name = null,
        ?string $mfaCode = null,
        ?CarbonInterface $expiresAt = null,
    ): array {
        $tenantUser = TenantUser::findForLogin($email);

        if (! $tenantUser || ! Hash::check($password, $tenantUser->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = User::on($tenantUser->getConnectionName())
            ->withoutGlobalScope('tenant')
            ->findOrFail($tenantUser->getKey());

        $tenant = $this->resolveTenantForUser($user, $tenantId);
        $tenantId = $tenant->id;
        $tokenName = $name ?: 'api-token';

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $this->assertMfaGrantAllowed($user, $tenant, $mfaCode);

            $newToken = $user->createToken($tokenName, ["tenant:{$tenantId}"]);

            if ($expiresAt !== null) {
                $newToken->accessToken->forceFill(['expires_at' => $expiresAt])->save();
            }

            $accessToken = $newToken->accessToken;

            app(AuditLoggerInterface::class)->log('api_token.created', [
                'tenant_id' => $tenantId,
                'actor_id' => $user->id,
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'metadata' => [
                    'token_id' => $accessToken->getKey(),
                    'name' => $tokenName,
                ],
            ]);

            return [
                'token' => $newToken->plainTextToken,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'tenant_id' => $tenantId,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function listTokensForTenant(User $user, string $tenantId): Collection
    {
        $ability = $this->tenantAbility($tenantId);

        return $user->tokens()
            ->get()
            ->filter(fn (PersonalAccessToken $token) => $this->tokenHasTenantAbility($token, $ability))
            ->values();
    }

    /**
     * @return array<int, array{id: int|string, name: string, abilities: array<int, string>, last_used_at: string|null, expires_at: string|null, created_at: string|null}>
     */
    public function formatTokenList(Collection $tokens): array
    {
        return $tokens->map(fn (PersonalAccessToken $token) => [
            'id' => $token->getKey(),
            'name' => $token->name,
            'abilities' => $token->abilities ?? [],
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ])->all();
    }

    public function revokeCurrentToken(User $user, string $tenantId): void
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            throw new ApiTokenException('TOKEN_NOT_FOUND', 'No active token to revoke.', 404);
        }

        if (! $this->tokenHasTenantAbility($token, $this->tenantAbility($tenantId))) {
            throw new ApiTokenException('TOKEN_NOT_FOUND', 'Token does not belong to this tenant context.', 404);
        }

        $this->revokeToken($user, $tenantId, $token);
    }

    public function revokeTokenById(User $user, string $tenantId, int|string $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->first();
        if (! $token || ! $this->tokenHasTenantAbility($token, $this->tenantAbility($tenantId))) {
            throw new ApiTokenException('TOKEN_NOT_FOUND', 'Token not found.', 404);
        }

        $this->revokeToken($user, $tenantId, $token);
    }

    protected function revokeToken(User $user, string $tenantId, PersonalAccessToken $token): void
    {
        $tokenId = $token->getKey();
        $tokenName = $token->name;
        $token->delete();

        app(AuditLoggerInterface::class)->log('api_token.revoked', [
            'tenant_id' => $tenantId,
            'actor_id' => $user->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'metadata' => [
                'token_id' => $tokenId,
                'name' => $tokenName,
                'revoked_by' => 'self',
            ],
        ]);
    }

    protected function resolveTenantForUser(User $user, ?string $tenantId): Tenant
    {
        if ($tenantId) {
            $hasAccess = $this->membershipService->hasActiveMembership($user->id, $tenantId);
            $tenant = $hasAccess ? Tenant::query()->find($tenantId) : null;

            if (! $tenant) {
                throw new ApiTokenException(
                    'TENANT_ACCESS_DENIED',
                    'You do not have access to the specified tenant.',
                    403
                );
            }

            return $tenant;
        }

        $tenant = $this->userService->getPersonalTenant($user)
            ?? $this->userService->getTenants($user)->first();

        if (! $tenant) {
            throw new ApiTokenException(
                'PERSONAL_TENANT_NOT_FOUND',
                'No active tenant membership found for user.',
                404
            );
        }

        return $tenant;
    }

    /**
     * @throws ApiTokenException
     */
    protected function assertMfaGrantAllowed(User $user, Tenant $tenant, ?string $mfaCode): void
    {
        if (! $this->mfaService->isMfaRequired($tenant)) {
            return;
        }

        if (! $this->mfaService->userHasConfirmedMfa($user)) {
            throw new ApiTokenException(
                'MFA_ENROLLMENT_REQUIRED',
                'MFA enrollment is required before issuing API tokens for this tenant.',
                403
            );
        }

        if ($mfaCode === null || $mfaCode === '') {
            throw ValidationException::withMessages([
                'mfa_code' => ['MFA verification code is required.'],
            ]);
        }

        if (! $this->mfaService->verifyCodeForGrant($user, $mfaCode)) {
            throw ValidationException::withMessages([
                'mfa_code' => ['Invalid MFA verification code.'],
            ]);
        }
    }

    protected function tenantAbility(string $tenantId): string
    {
        return "tenant:{$tenantId}";
    }

    protected function tokenHasTenantAbility(PersonalAccessToken $token, string $ability): bool
    {
        return in_array($ability, $token->abilities ?? [], true);
    }
}
