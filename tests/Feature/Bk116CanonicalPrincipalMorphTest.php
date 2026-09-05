<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Services\TenantOnboardingService;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BK-116: session principal FQCN must match Spatie model_has_* morph (BK-114 R2 class).
 */
class Bk116CanonicalPrincipalMorphTest extends TestCase
{
    private const CANONICAL = TenantUser::class;

    #[Test]
    public function registration_writes_spatie_morph_as_tenant_user_and_session_can_authorize(): void
    {
        $user = $this->registerTenantUser('BK116 Morph Owner', 'bk116-morph-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();

        $this->assertInstanceOf(TenantUser::class, $user);
        $this->assertSame(self::CANONICAL, $user::class);

        $morphTypes = DB::connection('tenant')
            ->table('model_has_roles')
            ->where('model_id', $user->getKey())
            ->pluck('model_type')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([self::CANONICAL], $morphTypes, 'Registration must write TenantUser morph only');

        Auth::login($user);
        $sessionUser = Auth::user();
        $this->assertInstanceOf(TenantUser::class, $sessionUser);
        $this->assertSame(self::CANONICAL, $sessionUser::class);
        $this->assertSame($user->getKey(), $sessionUser->getKey());

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        try {
            $this->assertTrue(
                $sessionUser->can('tenant.setup.view'),
                'Registered owner session must authorize setup.view with TenantUser morph'
            );
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    #[Test]
    public function onboarding_owner_morph_matches_auth_provider_model(): void
    {
        $email = 'bk116-onboard-'.uniqid().'@example.com';
        $password = 'password-Password1!';

        $result = app(TenantOnboardingService::class)->onboardOrganizationTenant(new TenantOnboardingInput(
            organizationName: 'BK116 Org '.uniqid(),
            ownerName: 'BK116 Owner',
            ownerEmail: $email,
            ownerPassword: $password,
            isolationLevel: 'shared',
        ));

        $owner = $result->owner;
        $this->assertInstanceOf(TenantUser::class, $owner);
        $tenant = $result->tenant;

        $morphTypes = DB::connection('tenant')
            ->table('model_has_roles')
            ->where('model_id', $owner->getKey())
            ->pluck('model_type')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([self::CANONICAL], $morphTypes);

        $this->assertSame(self::CANONICAL, config('auth.providers.tenant_users.model'));

        Auth::guard('web')->login($owner);
        $restored = Auth::guard('web')->user();
        $this->assertInstanceOf(TenantUser::class, $restored);
        $this->assertSame(self::CANONICAL, $restored::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        try {
            $this->assertTrue(
                $restored->can('tenant.setup.view'),
                'Onboarding owner session must authorize setup.view with TenantUser morph'
            );
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    #[Test]
    public function auth_provider_is_tenant_user_and_app_models_user_alias_is_gone(): void
    {
        $this->assertSame(self::CANONICAL, config('auth.providers.tenant_users.model'));
        $this->assertFalse(file_exists(base_path('app/Models/User.php')));
        $this->assertFalse(file_exists(base_path('Modules/Identity/app/Models/User.php')));
    }
}
