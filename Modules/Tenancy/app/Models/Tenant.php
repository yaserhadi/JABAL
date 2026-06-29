<?php

namespace Modules\Tenancy\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     * @deprecated BK-028 — use AppSetting on tenant DB via TenantSettingsService.
     */
    public function tenantSettings(): HasOne
    {
        return $this->hasOne(TenantSetting::class, 'tenant_id');
    }

    public function databaseConfig(): HasOne
    {
        return $this->hasOne(TenantDatabaseConfig::class, 'tenant_id');
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
