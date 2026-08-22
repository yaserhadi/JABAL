<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAVE-6: Explicit Business Owner relationship to Legal Organization.
 * References canonical User UUID — not a free-text email field, not Tenant role alone.
 */
class LegalOrganizationBusinessOwner extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'legal_organization_business_owners';

    protected $fillable = [
        'legal_organization_id',
        'user_id',
        'primary_tenant_id',
        'status',
        'assigned_at',
        'assigned_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function legalOrganization(): BelongsTo
    {
        return $this->belongsTo(LegalOrganization::class);
    }
}
