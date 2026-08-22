<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SetupDefinition extends Model
{
    use HasUuids;

    public const TYPE_BLOCKING = 'blocking';

    public const TYPE_OPTIONAL = 'optional';

    public const TYPE_CONDITIONAL = 'conditional';

    protected $connection = 'central';

    protected $fillable = [
        'code',
        'version',
        'title',
        'description',
        'requirement_type',
        'capability_code',
        'condition_entitlement_code',
        'product_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function states(): HasMany
    {
        return $this->hasMany(TenantSetupState::class);
    }
}
