<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    public function test_user_can_list_own_tokens_for_tenant(): void
    {
        $user = $this->registerTenantUser('List User', 'list-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->assignDashboardViewToUser($user, $tenant);

        $user->createToken('token-a', ['tenant:'.$tenant->id]);
        $user->createToken('token-b', ['tenant:'.$tenant->id]);

        $access = $user->createToken('bearer', ['tenant:'.$tenant->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$access->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/v1/auth/tokens');

        $response->assertOk();
        $names = collect($response->json('data.tokens'))->pluck('name')->all();
        $this->assertContains('token-a', $names);
        $this->assertContains('token-b', $names);
        $this->assertCount(3, $names);
    }

    public function test_user_cannot_list_another_users_tokens(): void
    {
        $owner = $this->registerTenantUser('Owner', 'owner-'.uniqid().'@example.com');
        $other = $this->registerTenantUser('Other', 'other-'.uniqid().'@example.com');
        $tenant = $owner->personalTenant();
        $this->assignDashboardViewToUser($owner, $tenant);
        $this->assignDashboardViewToUser($other, $tenant);

        app(UserService::class)->addUserToTenant($other, $tenant, 'member');

        $owner->createToken('owner-token', ['tenant:'.$tenant->id]);
        $other->createToken('other-token', ['tenant:'.$tenant->id]);

        $bearer = $other->createToken('bearer', ['tenant:'.$tenant->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/v1/auth/tokens');

        $response->assertOk();
        $names = collect($response->json('data.tokens'))->pluck('name')->all();
        $this->assertContains('other-token', $names);
        $this->assertNotContains('owner-token', $names);
    }

    public function test_user_can_revoke_token_by_id(): void
    {
        $user = $this->registerTenantUser('Revoke Id', 'revoke-id-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->assignDashboardViewToUser($user, $tenant);

        $tokenRow = $user->createToken('revoke-me', ['tenant:'.$tenant->id]);
        $tokenId = $tokenRow->accessToken->id;
        $bearer = $user->createToken('bearer', ['tenant:'.$tenant->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson('/api/v1/auth/tokens/'.$tokenId);

        $response->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId], 'tenant');
    }

    public function test_user_cannot_revoke_another_users_token_by_id(): void
    {
        $owner = $this->registerTenantUser('Owner Revoke', 'owner-revoke-'.uniqid().'@example.com');
        $other = $this->registerTenantUser('Other Revoke', 'other-revoke-'.uniqid().'@example.com');
        $tenant = $owner->personalTenant();
        $this->assignDashboardViewToUser($owner, $tenant);
        $this->assignDashboardViewToUser($other, $tenant);
        app(UserService::class)->addUserToTenant($other, $tenant, 'member');

        $ownerToken = $owner->createToken('owner-only', ['tenant:'.$tenant->id]);
        $bearer = $other->createToken('bearer', ['tenant:'.$tenant->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson('/api/v1/auth/tokens/'.$ownerToken->accessToken->id);

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'TOKEN_NOT_FOUND');
    }

    public function test_token_grant_requires_mfa_when_tenant_requires_it(): void
    {
        $user = $this->registerTenantUser('MFA Grant', 'mfa-grant-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->grantMfaEntitlements($tenant, required: true);

        tenancy()->initialize($tenant);
        $service = app(MfaService::class);
        $setup = $service->beginEnrollment($user);
        $code = (new Google2FA)->getCurrentOtp($setup['secret']);
        $service->confirmEnrollment($user, $code);
        tenancy()->end();

        $withoutMfa = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $withoutMfa->assertStatus(422)
            ->assertJsonValidationErrors(['mfa_code']);

        $freshCode = (new Google2FA)->getCurrentOtp($setup['secret']);
        $withMfa = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'mfa_code' => $freshCode,
        ]);
        $withMfa->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_token_grant_is_rate_limited(): void
    {
        $email = 'rate-'.uniqid().'@example.com';
        $this->registerTenantUser('Rate User', $email);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/token', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_token_grant_persists_optional_expires_at(): void
    {
        $user = $this->registerTenantUser('Expiry User', 'expiry-'.uniqid().'@example.com');
        $expiresAt = now()->addDays(30)->toIso8601String();

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'expires_at' => $expiresAt,
        ]);

        $response->assertOk();
        $tenant = $user->personalTenant();
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'api-token',
        ], 'tenant');

        $token = $user->tokens()->latest('id')->first();
        $this->assertNotNull($token?->expires_at);
    }

    public function test_token_create_and_revoke_emit_audit_events(): void
    {
        $user = $this->registerTenantUser('Audit User', 'audit-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $issue = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'name' => 'audit-token',
        ]);
        $issue->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('event', 'api_token.created')->where('tenant_id', $tenant->id)->exists()
        );

        $created = AuditLog::query()->where('event', 'api_token.created')->latest('created_at')->first();
        $this->assertIsArray($created->metadata);
        $this->assertArrayNotHasKey('token', $created->metadata ?? []);
        $this->assertArrayHasKey('token_id', $created->metadata ?? []);

        $bearer = $issue->json('data.token');
        $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson('/api/v1/auth/token')
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('event', 'api_token.revoked')->where('tenant_id', $tenant->id)->exists()
        );
    }

    protected function grantMfaEntitlements(Tenant $tenant, bool $required = false): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'mfa-api-test'],
            ['name' => 'MFA API Test', 'is_active' => true]
        );

        Entitlement::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'code' => 'mfa_available'],
            ['name' => 'MFA Available', 'is_active' => true]
        );

        if ($required) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => 'mfa_required'],
                ['name' => 'MFA Required', 'is_active' => true]
            );
        }

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );

        if ($required) {
            app(SecurityPolicyService::class)->update($tenant, ['mfa_required' => true]);
        }
    }
}
