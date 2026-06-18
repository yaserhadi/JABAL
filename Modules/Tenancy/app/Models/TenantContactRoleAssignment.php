<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantContactRoleAssignment extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'tenant_contact_role_assignments';

    protected $fillable = [
        'tenant_contact_id',
        'tenant_contact_role_id',
        'is_primary_for_role',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'is_primary_for_role' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(TenantContact::class, 'tenant_contact_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(TenantContactRole::class, 'tenant_contact_role_id');
    }
}
