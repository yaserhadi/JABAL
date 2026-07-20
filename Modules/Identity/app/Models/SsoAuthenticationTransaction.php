<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BK-082: Identity-owned central Authentication Transaction (DEC-0024 D2).
 */
class SsoAuthenticationTransaction extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_CALLBACK = 'awaiting_callback';

    public const STATUS_CALLBACK_RESERVED = 'callback_reserved';

    public const STATUS_HANDOFF_ISSUED = 'handoff_issued';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const PURPOSE_ORDINARY = 'ordinary';

    public const PURPOSE_REAUTHENTICATION = 'reauthentication';

    public const PURPOSE_STEP_UP = 'step_up';

    protected $connection = 'central';

    protected $table = 'sso_authentication_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'correlation_id',
        'tenant_id',
        'domain_id',
        'destination_host',
        'addressing_profile',
        'post_login_path',
        'idp_configuration_version_id',
        'expected_issuer',
        'purpose',
        'state_lookup',
        'state_secret_hash',
        'nonce_encrypted',
        'pkce_verifier_encrypted',
        'auth_binding_secret_hash',
        'tenant_continuation_secret_hash',
        'status',
        'failure_reason',
        'expires_at',
        'callback_reserved_at',
        'consumed_at',
        'secrets_erased_at',
    ];

    protected $hidden = [
        'state_secret_hash',
        'nonce_encrypted',
        'pkce_verifier_encrypted',
        'auth_binding_secret_hash',
        'tenant_continuation_secret_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'callback_reserved_at' => 'datetime',
        'consumed_at' => 'datetime',
        'secrets_erased_at' => 'datetime',
    ];

    public function handoff(): HasOne
    {
        return $this->hasOne(SsoTenantHandoff::class, 'transaction_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function secretsErased(): bool
    {
        return $this->secrets_erased_at !== null;
    }
}
