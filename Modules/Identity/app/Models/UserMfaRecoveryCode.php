<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserMfaRecoveryCode extends Model
{
    use HasUuids;

    protected $connection = 'tenant';

    protected $table = 'user_mfa_recovery_codes';

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $casts = ['used_at' => 'datetime'];
}
