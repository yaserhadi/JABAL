<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoIdentityResetTransaction extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const PURPOSE_RESET_SSO = 'reset_sso';

    public const PURPOSE_EMAIL_CHANGE = 'email_change';

    public const PURPOSE_IDP_MIGRATION_A = 'idp_migration_a';

    public const PURPOSE_IDP_MIGRATION_B = 'idp_migration_b';

    protected $connection = 'tenant';

    protected $table = 'sso_identity_reset_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'initiated_by_user_id',
        'purpose',
        'status',
        'current_identity_id',
        'candidate_identity_id',
        'compromised_current',
        'same_euid_reverification',
        'target_issuer',
        'target_idp_configuration_version_id',
        'completed_at',
    ];

    protected $casts = [
        'compromised_current' => 'boolean',
        'same_euid_reverification' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id')->withoutGlobalScope('tenant');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
