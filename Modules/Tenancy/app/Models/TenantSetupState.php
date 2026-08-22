<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetupState extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'setup_definition_id',
        'definition_version',
        'status',
        'completed_at',
        'completed_by',
        'evidence',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'evidence' => 'array',
        'definition_version' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SetupDefinition::class, 'setup_definition_id');
    }
}
