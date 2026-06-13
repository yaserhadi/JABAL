<?php

namespace Database\Seeders;

use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlatformRbacSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'platform.access',
        'platform.settings.manage',
        'platform.audit.view',
        'platform.users.create',
        'platform.roles.assign',
    ];

    public function run(): void
    {
        $permissionIds = [];
        foreach (self::PERMISSIONS as $name) {
            $permission = PlatformPermission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'platform']
            );
            $permissionIds[$name] = $permission->id;
        }

        $role = PlatformRole::firstOrCreate(
            ['name' => 'platform-super-admin', 'guard_name' => 'platform']
        );

        foreach ($permissionIds as $permissionId) {
            DB::connection('central')->table('platform_role_has_permissions')->insertOrIgnore([
                'platform_role_id' => $role->id,
                'platform_permission_id' => $permissionId,
            ]);
        }

        PlatformUser::query()->each(function (PlatformUser $user) use ($role) {
            DB::connection('central')->table('platform_model_has_roles')->insertOrIgnore([
                'platform_role_id' => $role->id,
                'model_type' => PlatformUser::class,
                'model_id' => $user->id,
            ]);
        });
    }
}
