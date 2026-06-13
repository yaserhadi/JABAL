<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tenant-layer MFA record (ADR-0007 — Wave 3 4B-1 rewrite). */
class UserMfa extends Model
{
    protected $connection = 'tenant';

    protected $table = 'user_mfa';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'secret', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserMfaRecoveryCode::class, 'user_id', 'user_id');
    }
}
