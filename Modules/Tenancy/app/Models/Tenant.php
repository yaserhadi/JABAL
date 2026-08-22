<?php

namespace Modules\Tenancy\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Tenancy\Exceptions\TenantHardDeleteProhibitedException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;
use Stancl\Tenancy\Database\Concerns\TenantRun;

/**
 * Tenant model implementing Stancl TenantContract.
 *
 * PHASE 2 LOCK:
 * - Implements TenantContract only (NO HasDatabase trait)
 * - Uses explicit 'central' connection
 * - NO BelongsToTenant trait (this IS the tenant)
 *
 * BK-064: No personal|organization type column. Path entry accepts slug or UUID.
 * BK-073: HasDomains — Stancl domain registry is the Host resolution authority.
 */
class Tenant extends Model implements TenantContract
{
    use Auditable;
    use HasDomains;
    use HasFactory;
    use HasInternalKeys;
    use HasUuids;
    use SoftDeletes;
    use TenantRun;

    protected static function booted(): void
    {
        static::forceDeleting(function (Tenant $tenant): void {
            throw new TenantHardDeleteProhibitedException(
                'Hard/force Tenant deletion is prohibited until BK-075 defines domain release policy (application-enforced; Tenant ['.$tenant->id.']).'
            );
        });
    }

    protected $connection = 'central';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\TenantFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'isolation_level',
        'status',
        'created_by',
        'commercial_owner_contact_id',
        'legal_organization_id',
        'offering_id',
        'setup_grandfathered',
    ];

    protected $casts = [
        'setup_grandfathered' => 'boolean',
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

    public function commercialOwnerContact(): BelongsTo
    {
        return $this->belongsTo(TenantContact::class, 'commercial_owner_contact_id');
    }

    public function legalOrganization(): BelongsTo
    {
        return $this->belongsTo(LegalOrganization::class, 'legal_organization_id');
    }

    /**
     * Human path key: prefer slug; UUID remains machine/compatibility.
     */
    public function entryKey(): string
    {
        return filled($this->slug) ? (string) $this->slug : (string) $this->id;
    }

    /**
     * Resolve /t/{tenant} by UUID or unique slug (BK-064).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->where($field, $value)->firstOrFail();
        }

        if (is_string($value) && Str::isUuid($value)) {
            return $this->where('id', $value)->firstOrFail();
        }

        return $this->where('slug', $value)->firstOrFail();
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
