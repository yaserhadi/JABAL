<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * BK-082 WS7: central Back-Channel Logout jti replay / idempotency record (D26).
 */
class SsoBackchannelLogoutEvent extends Model
{
    use HasUuids;

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'central';

    protected $table = 'sso_backchannel_logout_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'jti_hash',
        'tenant_id',
        'idp_configuration_version_id',
        'issuer_hash',
        'status',
        'failure_reason',
        'sessions_revoked',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
