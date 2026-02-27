<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantUser extends Pivot
{
    use HasFactory;
    use HasUuids;

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
