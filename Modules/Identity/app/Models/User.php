<?php

namespace Modules\Identity\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Spatie\Permission\Traits\HasRoles;

/**
 * Identity module User model (domain implementation).
 * Owns tenancy relationships and user-tenant business logic.
 * App\Models\User is a thin bridge extending this class for Laravel/Sanctum compatibility.
 */
class User extends Authenticatable
{
    use Auditable;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'central';
    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
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
        ];
    }

    /**
     * Tenant memberships (tenant_users).
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    /**
     * Tenants this user belongs to via membership.
     */
    public function tenants()
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_users',
            'user_id',
            'tenant_id'
        )->withPivot(['id', 'membership_type', 'status', 'joined_at'])
            ->withTimestamps()
            ->using(TenantUser::class);
    }

    /**
     * User's personal tenant (fallback for tenant resolution).
     * Returns the tenant where type is 'personal' and user is 'owner'.
     */
    public function personalTenant(): ?Tenant
    {
        return $this->tenants()
            ->wherePivot('membership_type', 'owner')
            ->where('tenants.type', 'personal')
            ->first();
    }
}
