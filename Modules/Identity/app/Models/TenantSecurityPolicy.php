<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/** Central metadata: tenant MFA requirement override (ADR-0007). */
class TenantSecurityPolicy extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_security_policies';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'mfa_required'];

    protected $casts = ['mfa_required' => 'boolean'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
