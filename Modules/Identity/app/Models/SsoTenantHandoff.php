<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BK-082: Identity-owned central Tenant Handoff (DEC-0024 D13).
 */
class SsoTenantHandoff extends Model
{
    use HasUuids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const AUDIENCE_TENANT_HOST = 'tenant_host';

    protected $connection = 'central';

    protected $table = 'sso_tenant_handoffs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'correlation_id',
        'transaction_id',
        'tenant_id',
        'domain_id',
        'destination_host',
        'audience',
        'post_login_path',
        'secret_hash',
        'tenant_continuation_secret_hash',
        'user_id',
        'identity_link_id',
        'assurance_evidence',
        'status',
        'failure_reason',
        'expires_at',
        'consumed_at',
        'secrets_erased_at',
    ];

    protected $hidden = [
        'secret_hash',
        'tenant_continuation_secret_hash',
    ];

    protected $casts = [
        'assurance_evidence' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'secrets_erased_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SsoAuthenticationTransaction::class, 'transaction_id');
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
