<?php

namespace Modules\Tenancy\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;
use Stancl\Tenancy\Database\Concerns\TenantRun;

/**
 * Tenant model implementing Stancl TenantContract.
 *
 * PHASE 2 LOCK:
 * - Implements TenantContract only (NO HasDatabase trait)
 * - Uses explicit 'central' connection
 * - NO BelongsToTenant trait (this IS the tenant)
 */
class Tenant extends Model implements TenantContract
{
    use Auditable;
    use HasFactory;
    use HasInternalKeys;
    use HasUuids;
    use SoftDeletes;
    use TenantRun;

    protected $connection = 'central';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\TenantFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'type',
        'isolation_level',
        'status',
        'created_by',
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

    /**
     * Stancl TenantContract: Get the name of the key used to identify the tenant.
     */
    public function getTenantKeyName(): string
    {
        return 'id';
    }

    /**
     * Stancl TenantContract: Get the value of the key used to identify the tenant.
     */
    public function getTenantKey(): mixed
    {
        return $this->id;
    }
}
