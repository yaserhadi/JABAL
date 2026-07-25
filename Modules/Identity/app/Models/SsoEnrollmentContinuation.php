<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BK-099: Auth Host → Tenant Host enrollment continuation (encrypted issuer+subject).
 * Not an ordinary login Handoff — Auth Host must not create TenantUserIdentity or session.
 */
class SsoEnrollmentContinuation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'central';

    protected $table = 'sso_enrollment_continuations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'transaction_id',
        'tenant_id',
        'invitation_id',
        'intended_user_id',
        'idp_configuration_version_id',
        'destination_host',
        'issuer_encrypted',
        'subject_encrypted',
        'lookup',
        'secret_hash',
        'browser_binding_secret_hash',
        'status',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'secret_hash',
        'browser_binding_secret_hash',
        'issuer_encrypted',
        'subject_encrypted',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SsoAuthenticationTransaction::class, 'transaction_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
