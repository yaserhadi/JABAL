<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvitation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'tenant';

    protected $table = 'tenant_invitations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'email',
        'intended_user_id',
        'invited_by_user_id',
        'token_hash',
        'role',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'invited_by_user_id')->withoutGlobalScope('tenant');
    }

    public function intendedUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'intended_user_id')->withoutGlobalScope('tenant');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function scopePending($query)
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}
