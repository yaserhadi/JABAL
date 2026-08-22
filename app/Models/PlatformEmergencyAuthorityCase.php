<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * WAVE-5: Platform Emergency Authority case (central).
 * Not ordinary Tenant Admin, not Generic Approval Engine, not permanent superuser.
 */
class PlatformEmergencyAuthorityCase extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'platform_emergency_authority_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REVOKED = 'revoked';

    public const CLASS_AVAILABILITY = 'availability';

    public const CLASS_COMPROMISE = 'compromise';

    protected $fillable = [
        'tenant_id',
        'platform_user_id',
        'reason',
        'classification',
        'status',
        'purpose',
        'emergency_tenant_user_id',
        'activated_at',
        'expires_at',
        'closed_at',
        'close_reason',
        'metadata',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function isCurrentlyActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
