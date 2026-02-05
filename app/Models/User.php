<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Audit\Auditable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class User extends Authenticatable
{
    use Auditable;
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use Notifiable;

    /**
     * The primary key type is UUID (string).
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
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
        return $this->hasMany(TenantUser::class);
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
