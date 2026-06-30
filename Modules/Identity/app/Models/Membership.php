<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/**
 * Tenant Application membership authority (ADR-0007 R11).
 * Replaces central tenant_users for auth/membership checks.
 */
class Membership extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected static function newFactory(): \Database\Factories\MembershipFactory
    {
        return \Database\Factories\MembershipFactory::new();
    }

    protected $connection = 'tenant';

    protected $table = 'memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'membership_type',
        'status',
        'joined_at',
        'removed_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id')->withoutGlobalScope('tenant');
    }

    public function scopeActiveMembers($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVisible($query)
    {
        return $query->where('status', '!=', 'removed');
    }

    public function scopeRemoved($query)
    {
        return $query->where('status', 'removed');
    }

    public function scopeOwners($query)
    {
        return $query->where('membership_type', 'owner');
    }

    public function isOwner(): bool
    {
        return $this->membership_type === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->membership_type === 'admin';
    }

    public function isRemoved(): bool
    {
        return $this->status === 'removed';
    }
}
