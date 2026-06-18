<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlatformPermission extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'platform_permissions';

    protected $fillable = ['name', 'guard_name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformRole::class,
            'platform_role_has_permissions',
            'platform_permission_id',
            'platform_role_id'
        );
    }
}
