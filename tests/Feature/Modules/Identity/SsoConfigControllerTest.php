<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Services\SsoConfigService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoConfigControllerTest extends TestCase
{
    use GrantsSsoEntitlement;

    protected User $admin;

    protected User $member;

    protected Tenant $tenant;

    private const SAFE_ISSUER = 'https://example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($this->tenant);

        tenancy()->initialize($this->tenant);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SSO Admin',
            'email' => 'sso-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->member = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SSO Member',
            'email' => 'sso-member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($this->tenant);
        $provisioner->assignTenantAdminRole($this->admin, $this->tenant);
        $this->assignMemberRole($this->member, $this->tenant);
    }

    #[Test]
    public function get_returns_safe_config_only(): void
    {
        tenancy()->initialize($this->tenant);
        app(SsoConfigService::class)->update($this->tenant, [
            'enabled' => true,
            'issuer_url' => self::SAFE_ISSUER,
            'client_id' => 'client-id',
            'client_secret' => 'hidden-secret',
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->getJson('/t/'.$this->tenant->id.'/security/sso');

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.has_client_secret', true);
        $response->assertJsonPath('data.issuer_url', self::SAFE_ISSUER);
        $response->assertJsonMissingPath('data.client_secret');
        $response->assertJsonMissingPath('data.client_secret_encrypted');
    }

    #[Test]
    public function patch_can_create_and_update_config(): void
    {
        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
                'provider_label' => 'Entra ID',
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => 'initial-secret',
                'scopes' => ['openid', 'profile', 'email'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.provider_label', 'Entra ID');
        $response->assertJsonPath('data.has_client_secret', true);
        $response->assertJsonMissingPath('data.client_secret');

        $update = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'provider_label' => 'Updated Provider',
            ]);

        $update->assertOk();
        $update->assertJsonPath('data.provider_label', 'Updated Provider');
        $update->assertJsonPath('data.enabled', true);
    }

    #[Test]
    public function patch_empty_client_secret_keeps_existing_secret(): void
    {
        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => 'keep-me-secret',
                'scopes' => ['openid', 'profile', 'email'],
            ])
            ->assertOk();

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'provider_label' => 'No secret change',
            ])
            ->assertOk()
            ->assertJsonPath('data.has_client_secret', true);

        tenancy()->initialize($this->tenant);
        $this->assertSame(
            'keep-me-secret',
            app(\Modules\Identity\Services\SsoConfigService::class)->getDecryptedClientSecret($this->tenant)
        );
        tenancy()->end();
    }

    #[Test]
    public function patch_client_secret_is_write_only_in_responses(): void
    {
        $secret = 'write-only-'.uniqid();

        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => $secret,
                'scopes' => ['openid', 'profile', 'email'],
            ]);

        $body = $response->assertOk()->getContent() ?? '';
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('client_secret_encrypted', $body);
        $response->assertJsonPath('data.has_client_secret', true);
    }

    #[Test]
    public function enabling_requires_entitlement(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        tenancy()->initialize($tenant);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'No Entitlement Admin',
            'email' => 'no-ent-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);
        $provisioner->assignTenantAdminRole($admin, $tenant);

        $response = $this->actingAsTenantUser($admin, $tenant)
            ->patchJson('/t/'.$tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => 'secret',
                'scopes' => ['openid', 'profile', 'email'],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['enabled']);
    }

    #[Test]
    public function enabling_blocked_when_disabled_by_entitlement_is_true(): void
    {
        tenancy()->initialize($this->tenant);
        app(SsoConfigService::class)->update($this->tenant, [
            'enabled' => false,
            'issuer_url' => self::SAFE_ISSUER,
            'client_id' => 'client-id',
            'client_secret' => 'secret',
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        TenantSsoConfig::query()->where('tenant_id', $this->tenant->id)->update([
            'disabled_by_entitlement' => true,
        ]);
        tenancy()->end();

        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['enabled']);
    }

    #[Test]
    public function get_requires_tenant_sso_view_permission(): void
    {
        $this->actingAsTenantUser($this->member, $this->tenant)
            ->getJson('/t/'.$this->tenant->id.'/security/sso')
            ->assertForbidden();
    }

    #[Test]
    public function patch_requires_tenant_sso_update_permission(): void
    {
        $this->actingAsTenantUser($this->member, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'provider_label' => 'Denied',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function tenant_without_sso_entitlement_cannot_enable_sso(): void
    {
        $user = $this->registerTenantUser('NoEntitlement', 'no-entitle-'.uniqid().'@example.com');
        $tenant = $user->homeTenant();
        app(TenantRbacProvisioner::class)->assignTenantAdminRole($user, $tenant);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson('/t/'.$tenant->id.'/security/sso')
            ->assertOk();

        $this->actingAsTenantUser($user, $tenant)
            ->patchJson('/t/'.$tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => 'secret',
                'scopes' => ['openid', 'profile', 'email'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enabled']);
    }

    #[Test]
    public function unsafe_issuer_url_is_rejected(): void
    {
        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => 'http://insecure.example.com',
                'client_id' => 'client-id',
                'client_secret' => 'secret',
                'scopes' => ['openid', 'profile', 'email'],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['issuer_url']);
    }

    #[Test]
    public function response_body_never_contains_secret_values(): void
    {
        $secret = 'never-expose-'.uniqid();

        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->getJson('/t/'.$this->tenant->id.'/security/sso');

        $this->actingAsTenantUser($this->admin, $this->tenant)
            ->patchJson('/t/'.$this->tenant->id.'/security/sso', [
                'enabled' => true,
                'issuer_url' => self::SAFE_ISSUER,
                'client_id' => 'client-id',
                'client_secret' => $secret,
                'scopes' => ['openid', 'profile', 'email'],
            ])
            ->assertOk();

        $getBody = $response->getContent() ?? '';
        $patchBody = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->getJson('/t/'.$this->tenant->id.'/security/sso')
            ->assertOk()
            ->getContent() ?? '';

        $this->assertStringNotContainsString($secret, $getBody);
        $this->assertStringNotContainsString($secret, $patchBody);
        $this->assertStringNotContainsString('client_secret_encrypted', $patchBody);
    }

    protected function assignMemberRole(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $guard = config('auth.defaults.guard');
        $role = Role::firstOrCreate(
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        foreach (['dashboard.view', 'workspace.view'] as $perm) {
            $permission = Permission::findByName($perm, $guard);
            if ($permission && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
