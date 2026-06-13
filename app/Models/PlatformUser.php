<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform Management operator — central DB only (ADR-0007).
 */
class PlatformUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'central';

    protected $table = 'platform_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isPlatformOperator(): bool
    {
        return (bool) $this->is_active;
    }

    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $direct = \DB::connection('central')
            ->table('platform_model_has_permissions')
            ->join('platform_permissions', 'platform_permissions.id', '=', 'platform_model_has_permissions.platform_permission_id')
            ->where('platform_model_has_permissions.model_type', self::class)
            ->where('platform_model_has_permissions.model_id', $this->id)
            ->where('platform_permissions.name', $permission)
            ->exists();

        if ($direct) {
            return true;
        }

        return \DB::connection('central')
            ->table('platform_model_has_roles')
            ->join('platform_role_has_permissions', 'platform_role_has_permissions.platform_role_id', '=', 'platform_model_has_roles.platform_role_id')
            ->join('platform_permissions', 'platform_permissions.id', '=', 'platform_role_has_permissions.platform_permission_id')
            ->where('platform_model_has_roles.model_type', self::class)
            ->where('platform_model_has_roles.model_id', $this->id)
            ->where('platform_permissions.name', $permission)
            ->exists();
    }

    public function canAccessPlatform(): bool
    {
        return $this->hasPlatformPermission('platform.access');
    }

    public function homeRedirectPath(): string
    {
        return route('platform.settings.index');
    }
}
