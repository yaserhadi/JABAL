<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\RejectTenancyContextConflict;
use App\Models\Domain;
use App\Support\Tenancy\TenantBoundSignedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\TenantInvitation;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantHandleAllocation;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Modules\Tenancy\Services\TenantHandleLifecycleService;
use Modules\Tenancy\Support\TenantHandleValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BK-125 Wave 7 / CONFLICT-F — Tenant handle rename + safe reuse.
 */
class TenantHandleRenameReuseTest extends TestCase
{
    use RefreshDatabase;

    private TenantHandleLifecycleService $lifecycle;

    private TenantHandleValidator $handles;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenant_handles.quarantine_days' => 30]);
        $this->lifecycle = app(TenantHandleLifecycleService::class);
        $this->handles = app(TenantHandleValidator::class);
    }

    private function tenantWithHandle(string $handle): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => $handle,
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $this->lifecycle->recordInitialAssignment($tenant);

        return $tenant->fresh();
    }

    #[Test]
    public function rename_preserves_tenant_id_and_retires_old_handle(): void
    {
        $tenant = $this->tenantWithHandle('acme');
        $tenantId = (string) $tenant->id;

        $result = $this->lifecycle->rename($tenant, 'acme-industries');

        $this->assertSame($tenantId, (string) $result['tenant']->id);
        $this->assertSame('acme-industries', $result['tenant']->slug);
        $this->assertSame('acme', $result['old_handle']);
        $this->assertSame('acme-industries', $result['new_handle']);

        $this->assertDatabaseMissing('domains', ['domain' => 'acme'], 'central');
        $this->assertDatabaseHas('domains', [
            'domain' => 'acme-industries',
            'tenant_id' => $tenantId,
        ], 'central');

        $retired = TenantHandleAllocation::query()
            ->where('handle', 'acme')
            ->where('status', TenantHandleAllocation::STATUS_RETIRED)
            ->first();
        $this->assertNotNull($retired);
        $this->assertSame($tenantId, (string) $retired->tenant_id);

        $active = TenantHandleAllocation::query()
            ->where('handle', 'acme-industries')
            ->where('status', TenantHandleAllocation::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($active);

        $this->assertSame(
            TenantHandleValidator::CODE_TAKEN,
            $this->handles->evaluate('acme', checkAvailability: true)['code']
        );
    }

    #[Test]
    public function retired_handle_cannot_be_immediately_reused(): void
    {
        $tenant = $this->tenantWithHandle('reuse-block');
        $this->lifecycle->rename($tenant, 'reuse-block-new');

        $other = Tenant::factory()->create(['slug' => 'other-org', 'status' => 'active']);
        $this->lifecycle->recordInitialAssignment($other);

        $this->expectException(ValidationException::class);
        $this->lifecycle->rename($other, 'reuse-block');
    }

    #[Test]
    public function quarantine_alone_does_not_release_when_dependencies_remain(): void
    {
        $tenant = $this->tenantWithHandle('dep-hold');
        $this->lifecycle->rename($tenant, 'dep-hold-new');

        // Re-attach domain row on retired handle → release-blocking.
        Domain::query()->create([
            'domain' => 'dep-hold',
            'tenant_id' => $tenant->id,
            'data' => ['category' => 'platform_subdomain'],
        ]);

        TenantHandleAllocation::query()
            ->where('handle', 'dep-hold')
            ->where('status', TenantHandleAllocation::STATUS_RETIRED)
            ->update(['retired_at' => now()->subDays(60)]);

        $evaluation = $this->lifecycle->evaluateRelease('dep-hold');
        $this->assertTrue($evaluation['quarantine_elapsed']);
        $this->assertFalse($evaluation['can_release']);
        $this->assertSame('release-blocking', $evaluation['dependencies']['domain_mapping']);
    }

    #[Test]
    public function dependency_clear_alone_does_not_bypass_minimum_quarantine(): void
    {
        config(['tenant_handles.quarantine_days' => 30]);
        $tenant = $this->tenantWithHandle('early-rel');
        $this->lifecycle->rename($tenant, 'early-rel-new');

        $evaluation = $this->lifecycle->evaluateRelease('early-rel');
        $this->assertFalse($evaluation['quarantine_elapsed']);
        $this->assertFalse($evaluation['can_release']);
        $this->assertSame('cleared', $evaluation['dependencies']['domain_mapping']);
    }

    #[Test]
    public function release_succeeds_when_quarantine_and_dependencies_pass_then_reassign(): void
    {
        config(['tenant_handles.quarantine_days' => 0]);
        $tenantA = $this->tenantWithHandle('acme');
        $idA = (string) $tenantA->id;

        $this->lifecycle->rename($tenantA, 'alpha');
        $this->assertSame($idA, (string) $tenantA->fresh()->id);

        $evaluation = $this->lifecycle->evaluateRelease('acme');
        $this->assertTrue($evaluation['can_release']);

        $released = $this->lifecycle->release('acme');
        $this->assertSame(TenantHandleAllocation::STATUS_AVAILABLE, $released->status);

        $this->assertSame(
            TenantHandleValidator::CODE_AVAILABLE,
            $this->handles->evaluate('acme', checkAvailability: true)['code']
        );

        $tenantB = $this->tenantWithHandle('tenant-b-tmp');
        $idB = (string) $tenantB->id;
        $this->lifecycle->rename($tenantB, 'acme');

        $this->assertSame($idB, (string) $tenantB->fresh()->id);
        $this->assertSame('acme', $tenantB->fresh()->slug);
        $this->assertNotSame($idA, $idB);

        $this->assertDatabaseHas('domains', [
            'domain' => 'acme',
            'tenant_id' => $idB,
        ], 'central');
    }

    #[Test]
    public function reserved_handle_cannot_be_assigned(): void
    {
        $tenant = $this->tenantWithHandle('ok-org');
        $this->expectException(ValidationException::class);
        $this->lifecycle->rename($tenant, 'platform');
    }

    #[Test]
    public function normalization_uniqueness_is_case_insensitive_and_race_safe_at_db(): void
    {
        $tenant = $this->tenantWithHandle('norm-case');
        $this->lifecycle->rename($tenant, 'Norm-Case-Two');

        $this->assertSame('norm-case-two', $tenant->fresh()->slug);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('central')->table('tenant_handle_allocations')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'handle' => 'norm-case-two',
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'is_canonical' => true,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function session_mismatch_after_reuse_denies_and_invalidates_path(): void
    {
        config(['tenant_handles.quarantine_days' => 0]);
        $tenantA = $this->tenantWithHandle('acme');
        $idA = (string) $tenantA->id;

        $this->lifecycle->rename($tenantA, 'alpha');
        $this->lifecycle->release('acme');

        $tenantB = $this->tenantWithHandle('tmp-b');
        $this->lifecycle->rename($tenantB, 'acme');
        $idB = (string) $tenantB->fresh()->id;

        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenantB->fresh());

        $request = Request::create('/t/acme/login', 'GET');
        $session = app('session.store');
        $session->start();
        $session->put('tenant_id', $idA);
        $session->put('proof', 'stale');
        $request->setLaravelSession($session);

        try {
            app(RejectTenancyContextConflict::class)->handle(
                $request,
                static fn () => response('ok')
            );
            $this->fail('Expected session Tenant ID mismatch to deny.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertFalse($request->session()->has('tenant_id'));
            $this->assertFalse($request->session()->has('proof'));
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }

        $this->assertSame($idB, (string) Tenant::query()->where('slug', 'acme')->value('id'));
        $this->assertNotSame($idA, $idB);
    }

    #[Test]
    public function session_mismatch_after_reuse_denies_on_path_host(): void
    {
        // Profile-agnostic conflict guard; PATH_HOST request shape only (no app reboot —
        // refreshApplication would break RefreshDatabase transaction isolation).
        config(['tenant_handles.quarantine_days' => 0]);

        $tenantA = $this->tenantWithHandle('ph-acme');
        $idA = (string) $tenantA->id;
        $this->lifecycle->rename($tenantA, 'ph-alpha');
        $this->lifecycle->release('ph-acme');

        $tenantB = $this->tenantWithHandle('ph-tmp-b');
        $this->lifecycle->rename($tenantB, 'ph-acme');

        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenantB->fresh());

        $request = Request::create('https://ph-acme.jabal.test/login', 'GET');
        $request->headers->set('HOST', 'ph-acme.jabal.test');
        $session = app('session.store');
        $session->start();
        $session->put('tenant_id', $idA);
        $request->setLaravelSession($session);

        try {
            app(RejectTenancyContextConflict::class)->handle(
                $request,
                static fn () => response('ok')
            );
            $this->fail('Expected deny.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertFalse($request->session()->has('tenant_id'));
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function invitation_remains_bound_to_original_tenant_after_handle_reuse(): void
    {
        config(['tenant_handles.quarantine_days' => 0]);
        $tenantA = $this->tenantWithHandle('invite-acme');
        $idA = (string) $tenantA->id;

        tenancy()->initialize($tenantA);
        $inviter = \Modules\Identity\Models\TenantUser::query()->create([
            'tenant_id' => $idA,
            'name' => 'Inviter',
            'email' => 'inviter-'.uniqid().'@example.test',
            'password' => 'password',
        ]);
        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $idA,
            'email' => 'member@example.test',
            'invited_by_user_id' => $inviter->id,
            'token_hash' => hash('sha256', 'invite-token-wave7'),
            'role' => 'member',
            'expires_at' => now()->addDay(),
        ]);
        tenancy()->end();

        $this->lifecycle->rename($tenantA, 'invite-alpha');
        $this->lifecycle->release('invite-acme');

        $tenantB = $this->tenantWithHandle('invite-tmp-b');
        $this->lifecycle->rename($tenantB, 'invite-acme');
        $idB = (string) $tenantB->fresh()->id;

        tenancy()->initialize($tenantA->fresh());
        $loaded = TenantInvitation::query()->findOrFail($invitation->id);
        $this->assertSame($idA, (string) $loaded->tenant_id);
        $this->assertNotSame($idB, (string) $loaded->tenant_id);
        tenancy()->end();
    }

    #[Test]
    public function signed_url_with_tenant_id_rejects_after_handle_reuse(): void
    {
        config(['tenant_handles.quarantine_days' => 0]);
        $tenantA = $this->tenantWithHandle('sig-acme');
        $idA = (string) $tenantA->id;

        $this->lifecycle->rename($tenantA, 'sig-alpha');
        $this->lifecycle->release('sig-acme');
        $tenantB = $this->tenantWithHandle('sig-tmp');
        $this->lifecycle->rename($tenantB, 'sig-acme');

        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenantB->fresh());

        $request = Request::create('/t/sig-acme/login', 'GET', [
            'tenant_id' => $idA,
            'expires' => now()->addMinutes(5)->getTimestamp(),
            'signature' => 'ignored-for-binding-check',
        ]);

        try {
            TenantBoundSignedUrl::assertMatchesResolvedTenant($request);
            $this->fail('Expected signed URL Tenant ID mismatch.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function open_sso_state_for_old_host_is_failed_on_rename(): void
    {
        $tenant = $this->tenantWithHandle('sso-acme');
        $fqdn = 'sso-acme.jabal.test';

        $created = app(\Modules\Identity\Services\AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $fqdn,
            'addressing_profile' => 'path',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => (string) \Illuminate\Support\Str::uuid(),
            'expected_issuer' => 'https://idp.example.test',
        ]);

        /** @var SsoAuthenticationTransaction $txn */
        $txn = $created['transaction'];
        $txn->forceFill(['status' => SsoAuthenticationTransaction::STATUS_AWAITING_CALLBACK])->save();

        $this->lifecycle->rename($tenant, 'sso-alpha');

        $txn->refresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);
        $this->assertSame('tenant_handle_renamed', $txn->failure_reason);
    }

    #[Test]
    public function stale_handle_resolution_cache_must_not_survive_rename(): void
    {
        $tenant = $this->tenantWithHandle('cache-acme');
        $cacheKey = 'tenant_handle_resolve:cache-acme';
        Cache::put($cacheKey, (string) $tenant->id, 600);

        $this->lifecycle->rename($tenant, 'cache-alpha');
        Cache::forget($cacheKey);

        $this->assertNull(Cache::get($cacheKey));
        $this->assertSame('cache-alpha', $tenant->fresh()->slug);
    }

    #[Test]
    public function platform_rename_handle_route_preserves_tenant_id(): void
    {
        $user = $this->platformUserWithPermissions(['platform.tenants.update', 'platform.tenants.view']);
        $tenant = $this->tenantWithHandle('plat-rename');
        $id = (string) $tenant->id;

        $this->actingAs($user, 'platform')
            ->post(route('platform.tenants.rename-handle', $tenant), [
                'handle' => 'plat-renamed',
            ])
            ->assertRedirect(route('platform.tenants.show', $id));

        $this->assertSame($id, (string) $tenant->fresh()->id);
        $this->assertSame('plat-renamed', $tenant->fresh()->slug);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function platformUserWithPermissions(array $permissions): \App\Models\PlatformUser
    {
        $user = \App\Models\PlatformUser::create([
            'name' => 'Handle Operator',
            'email' => 'handle-'.uniqid().'@platform.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
        ]);

        $names = array_values(array_unique(array_merge(['platform.access'], $permissions)));
        $role = \App\Models\PlatformRole::firstOrCreate([
            'name' => 'handle-test-'.uniqid(),
            'guard_name' => 'platform',
        ]);

        foreach ($names as $name) {
            $permission = \App\Models\PlatformPermission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'platform',
            ]);
            DB::connection('central')->table('platform_role_has_permissions')->insertOrIgnore([
                'platform_role_id' => $role->id,
                'platform_permission_id' => $permission->id,
            ]);
        }

        DB::connection('central')->table('platform_model_has_roles')->insertOrIgnore([
            'platform_role_id' => $role->id,
            'model_type' => \App\Models\PlatformUser::class,
            'model_id' => $user->id,
        ]);

        return $user;
    }
}
