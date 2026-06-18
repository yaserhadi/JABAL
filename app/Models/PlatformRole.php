<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlatformRole extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'platform_roles';

    protected $fillable = ['name', 'guard_name'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformPermission::class,
            'platform_role_has_permissions',
            'platform_role_id',
            'platform_permission_id'
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformUser::class,
            'platform_model_has_roles',
            'platform_role_id',
            'model_id'
        )->wherePivot('model_type', PlatformUser::class);
    }
}
