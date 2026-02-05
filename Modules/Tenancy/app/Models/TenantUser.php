<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUser extends Model
{
    use HasUuids;

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
        return $this->belongsTo(User::class);
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
