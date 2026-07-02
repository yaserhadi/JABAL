<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    public const DEFAULT_CODE = 'standard';

    protected $connection = 'central';

    protected $fillable = ['code', 'name', 'description', 'is_active', 'seat_limit'];

    protected $casts = ['is_active' => 'boolean'];

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }
}
