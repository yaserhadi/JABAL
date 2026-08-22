<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * WAVE-5: Explicit temporary Password LOGIN recovery (never automatic).
 */
class TemporaryPasswordRecovery extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'temporary_password_recoveries';

    protected $keyType = 'string';

    public $incrementing = false;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const CLASS_AVAILABILITY = 'availability';

    public const CLASS_COMPROMISE = 'compromise';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'reason',
        'status',
        'classification',
        'created_by_type',
        'created_by_id',
        'pea_case_id',
        'activated_at',
        'expires_at',
        'revoked_at',
        'revoked_by_id',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isCurrentlyValid(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
