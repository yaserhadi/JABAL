<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\SessionRegistryService;
use Tests\TestCase;

class SessionRegistryServiceTest extends TestCase
{
    protected SessionRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SessionRegistryService::class);
    }

    public function test_register_creates_session_record(): void
    {
        $user = $this->registerTenantUser('Session User', 'sess-reg-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = \Illuminate\Http\Request::create('/login', 'POST');
        $request->server->set('REMOTE_ADDR', '192.168.1.100');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0');

        $record = $this->service->register($user, $request, 'test-session-id-001');

        $this->assertInstanceOf(UserSession::class, $record);
        $this->assertEquals($user->id, $record->user_id);
        $this->assertEquals($tenant->id, $record->tenant_id);
        $this->assertEquals('test-session-id-001', $record->session_id);
        $this->assertEquals('192.168.1.100', $record->ip_address);
        $this->assertStringContainsString('Chrome', $record->device_label);
        $this->assertNotNull($record->logged_in_at);
        $this->assertNotNull($record->last_activity_at);
        $this->assertNull($record->revoked_at);
    }

    public function test_revoke_marks_record_as_revoked(): void
    {
        $user = $this->registerTenantUser('Revoke User', 'sess-rev-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $record = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'sess-to-revoke',
            'ip_address' => '10.0.0.1',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->service->revoke($record->id);

        $record->refresh();
        $this->assertNotNull($record->revoked_at);
    }

    public function test_revoke_all_for_user_except_current(): void
    {
        $user = $this->registerTenantUser('RevokeAll User', 'sess-rall-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $current = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'current-session',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $other1 = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'other-session-1',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $other2 = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'other-session-2',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $count = $this->service->revokeAllForUser($user, $current->id);

        $this->assertEquals(2, $count);
        $this->assertNull($current->refresh()->revoked_at);
        $this->assertNotNull($other1->refresh()->revoked_at);
        $this->assertNotNull($other2->refresh()->revoked_at);
    }

    public function test_list_for_user_returns_active_only(): void
    {
        $user = $this->registerTenantUser('List User', 'sess-list-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'active-sess',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'revoked-sess',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
            'revoked_at' => now(),
        ]);

        $list = $this->service->listForUser($user);

        $this->assertCount(1, $list);
        $this->assertEquals('active-sess', $list->first()->session_id);
    }

    public function test_cleanup_prunes_old_records(): void
    {
        $user = $this->registerTenantUser('Cleanup User', 'sess-clean-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'old-revoked',
            'last_activity_at' => now()->subDays(40),
            'logged_in_at' => now()->subDays(40),
            'revoked_at' => now()->subDays(35),
        ]);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'old-inactive',
            'last_activity_at' => now()->subDays(40),
            'logged_in_at' => now()->subDays(40),
        ]);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'recent-active',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $deleted = $this->service->cleanup(30);

        $this->assertEquals(2, $deleted);
        $this->assertDatabaseHas('user_sessions', ['session_id' => 'recent-active'], 'tenant');
        $this->assertDatabaseMissing('user_sessions', ['session_id' => 'old-revoked'], 'tenant');
        $this->assertDatabaseMissing('user_sessions', ['session_id' => 'old-inactive'], 'tenant');
    }

    public function test_tenant_isolation(): void
    {
        $user1 = $this->registerTenantUser('Tenant1 User', 'sess-iso1-'.uniqid().'@example.com');
        $tenant1 = $user1->personalTenant();

        $user2 = $this->registerTenantUser('Tenant2 User', 'sess-iso2-'.uniqid().'@example.com');
        $tenant2 = $user2->personalTenant();

        $this->actingAsTenant($tenant1);
        UserSession::create([
            'tenant_id' => $tenant1->id,
            'user_id' => $user1->id,
            'session_id' => 'tenant1-session',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->actingAsTenant($tenant2);
        UserSession::create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user2->id,
            'session_id' => 'tenant2-session',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $list = $this->service->listForUser($user2);
        $this->assertCount(1, $list);
        $this->assertEquals('tenant2-session', $list->first()->session_id);
    }

    public function test_is_revoked(): void
    {
        $user = $this->registerTenantUser('IsRevoked User', 'sess-isrev-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'check-revoked',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
            'revoked_at' => now(),
        ]);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'check-active',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->assertTrue($this->service->isRevoked('check-revoked'));
        $this->assertFalse($this->service->isRevoked('check-active'));
    }

    public function test_is_revoked_returns_false_for_null_session_id(): void
    {
        $user = $this->registerTenantUser('NullSess User', 'sess-null-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => null,
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->assertFalse($this->service->isRevoked('nonexistent-session'));
    }

    public function test_touch_updates_last_activity(): void
    {
        $user = $this->registerTenantUser('Touch User', 'sess-touch-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $past = now()->subHours(2);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'touch-session',
            'last_activity_at' => $past,
            'logged_in_at' => $past,
        ]);

        $this->service->touch('touch-session');

        $record = UserSession::where('session_id', 'touch-session')->first();
        $this->assertTrue($record->last_activity_at->isAfter($past));
    }

    public function test_list_for_current_tenant_user_scopes_by_tenant(): void
    {
        $user1 = $this->registerTenantUser('Tenant1 User', 'sess-ctx1-'.uniqid().'@example.com');
        $tenant1 = $user1->personalTenant();

        $user2 = $this->registerTenantUser('Tenant2 User', 'sess-ctx2-'.uniqid().'@example.com');
        $tenant2 = $user2->personalTenant();
        $this->createMembership($user1, $tenant2, 'member', 'active');

        $this->actingAsTenant($tenant1);
        UserSession::create([
            'tenant_id' => $tenant1->id,
            'user_id' => $user1->id,
            'session_id' => 'tenant1-only',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->actingAsTenant($tenant2);
        UserSession::create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user1->id,
            'session_id' => 'tenant2-only',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $list = $this->service->listForCurrentTenantUser($user1, $tenant2);

        $this->assertCount(1, $list);
        $this->assertEquals('tenant2-only', $list->first()->session_id);
    }

    public function test_revoke_for_current_tenant_user_enforces_ownership(): void
    {
        $owner = $this->registerTenantUser('Owner', 'sess-own-'.uniqid().'@example.com');
        $tenant = $owner->personalTenant();
        $other = $this->registerTenantUser('Intruder', 'sess-intr-'.uniqid().'@example.com');
        $this->createMembership($other, $tenant, 'member', 'active');

        $this->actingAsTenant($tenant);
        $record = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'session_id' => 'owner-session',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service->revokeForCurrentTenantUser($other, $tenant, $record->id);
    }

    public function test_revoke_other_sessions_for_current_tenant_user_uses_laravel_session_id(): void
    {
        $user = $this->registerTenantUser('RevokeOther', 'sess-ro-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'keep-this',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $other = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'revoke-this',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $count = $this->service->revokeOtherSessionsForCurrentTenantUser($user, $tenant, 'keep-this');

        $this->assertEquals(1, $count);
        $this->assertNull(UserSession::where('session_id', 'keep-this')->first()->revoked_at);
        $this->assertNotNull($other->refresh()->revoked_at);
    }
}
