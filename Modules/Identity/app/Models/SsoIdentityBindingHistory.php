<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SsoIdentityBindingHistory extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'tenant';

    protected $table = 'sso_identity_binding_history';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'identity_id',
        'reset_transaction_id',
        'issuer',
        'subject',
        'email_at_link',
        'verification_status',
        'binding_role',
        'event',
        'ready_at',
    ];

    protected $casts = [
        'ready_at' => 'datetime',
    ];
}
