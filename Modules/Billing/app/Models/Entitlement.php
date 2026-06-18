<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['plan_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
