<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @deprecated Central membership bridge — **read-only legacy**. Current authority:
 *     {@see \Modules\Identity\Models\Membership} on tenant layer (R11).
 * Status: Deprecated, read-only, scheduled for removal — see
 *     docs/reports/PHASE4_REHOME_FOUNDATION.md §9.1. Do not add new writes or auth checks.
 */
class TenantUser extends Pivot
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'central';
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\TenantUserFactory::new();
    }

    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'membership_type',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->applicationUser();
    }

    /**
     * Tenant-application user (tenant DB). Membership is authoritative on this pivot;
     * do not scope by users.tenant_id — shared_db users may belong via central tenant_users
     * while their home tenant_id points at another workspace.
     */
    public function applicationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope('tenant');
    }

    public function scopeActiveMembers($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOwners($query)
    {
        return $query->where('membership_type', 'owner');
    }

    public function scopeByMembershipType($query, string $type)
    {
        return $query->where('membership_type', $type);
    }

    public function isOwner(): bool
    {
        return $this->membership_type === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->membership_type === 'admin';
    }
}
