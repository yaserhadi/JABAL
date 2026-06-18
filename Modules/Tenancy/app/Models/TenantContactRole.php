<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantContactRole extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'tenant_contact_roles';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(TenantContactRoleAssignment::class);
    }
}
