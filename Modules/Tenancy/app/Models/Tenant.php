<?php

namespace Modules\Tenancy\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use Auditable;
    use HasFactory;

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\TenantFactory::new();
    }
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'isolation_level',
    ];

    protected $casts = [
        //
    ];

    /**
     * Tenant membership records (tenant_users).
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Users belonging to this tenant via membership.
     */
    public function users()
    {
        return $this->belongsToMany(
            \App\Models\User::class,
            'tenant_users',
            'tenant_id',
            'user_id'
        )->withPivot(['id', 'membership_type', 'status', 'joined_at'])
            ->withTimestamps()
            ->using(TenantUser::class);
    }

    /**
     * Scope: personal tenants only.
     */
    public function scopePersonal($query)
    {
        return $query->where('type', 'personal');
    }

    public function isPersonal(): bool
    {
        return $this->type === 'personal';
    }

    /**
     * Scope: organization tenants only.
     */
    public function scopeOrganization($query)
    {
        return $query->where('type', 'organization');
    }
}
