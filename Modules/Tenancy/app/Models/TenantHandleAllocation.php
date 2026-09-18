<?php

declare(strict_types=1);

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BK-125 Wave 7 — handle lifecycle row (central).
 */
class TenantHandleAllocation extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_RELEASABLE = 'releasable';

    public const STATUS_AVAILABLE = 'available';

    public const BLOCKING_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RETIRED,
        self::STATUS_RELEASABLE,
    ];

    protected $connection = 'central';

    protected $table = 'tenant_handle_allocations';

    protected $fillable = [
        'handle',
        'tenant_id',
        'status',
        'is_canonical',
        'assigned_at',
        'retired_at',
        'releasable_at',
        'released_at',
        'superseded_by_handle',
        'actor_id',
        'reason',
    ];

    protected $casts = [
        'is_canonical' => 'boolean',
        'assigned_at' => 'datetime',
        'retired_at' => 'datetime',
        'releasable_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }
}
