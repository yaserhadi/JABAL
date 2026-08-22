<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCanonicalEmailChangeRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $connection = 'tenant';

    protected $table = 'user_canonical_email_change_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'initiated_by_user_id',
        'current_email',
        'proposed_email',
        'token_hash',
        'status',
        'requires_reset_sso',
        'reset_transaction_id',
        'expires_at',
        'verified_at',
        'completed_at',
    ];

    protected $casts = [
        'requires_reset_sso' => 'boolean',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id')->withoutGlobalScope('tenant');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
