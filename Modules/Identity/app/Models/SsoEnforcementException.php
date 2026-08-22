<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * WAVE-5: Temporary Per-User SSO Enforcement Exception (≠ Ready, ≠ policy change).
 */
class SsoEnforcementException extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'sso_enforcement_exceptions';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const CLOSURE_AUTOMATIC = 'automatic';

    public const CLOSURE_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'reason',
        'status',
        'closure_mode',
        'created_by_user_id',
        'expires_at',
        'closed_at',
        'closed_by_user_id',
        'close_reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isCurrentlyValid(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
