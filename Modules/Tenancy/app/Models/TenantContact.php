<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commercial/admin contact on central registry (ADR-0007 R11). Not a login identity.
 */
class TenantContact extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'tenant_contacts';

    protected $fillable = [
        'tenant_id',
        'full_name',
        'email',
        'phone',
        'mobile',
        'job_title',
        'department',
        'organization_name',
        'preferred_language',
        'preferred_channel',
        'status',
        'notes',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(TenantContactRoleAssignment::class);
    }
}
