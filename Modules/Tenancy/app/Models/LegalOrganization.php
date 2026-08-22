<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WAVE-6 GAP-002: Customer Legal Organization ≠ Tenant.
 */
class LegalOrganization extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'central';

    protected $fillable = ['name', 'status', 'external_reference', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function businessOwners(): HasMany
    {
        return $this->hasMany(LegalOrganizationBusinessOwner::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'legal_organization_id');
    }
}
