<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Billing\Services\OfferingPublishGate;

/**
 * WAVE-6: Published commercial Offering (Product + Plan SKU + capabilities).
 */
class Offering extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $connection = 'central';

    protected $fillable = [
        'code',
        'name',
        'product_id',
        'plan_id',
        'status',
        'version',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (Offering $offering): void {
            if (! $offering->isDirty('status')) {
                return;
            }
            if ($offering->status !== self::STATUS_PUBLISHED) {
                return;
            }
            if ($offering->getOriginal('status') === self::STATUS_PUBLISHED) {
                return;
            }
            $gate = app(OfferingPublishGate::class);
            // Model writes inherit active override context from ProductCatalogService::publish;
            // bare status flips never get silent incomplete publish.
            $gate->assertMayPublish($offering, $gate->isExplicitOverrideActive());
        });

        static::creating(function (Offering $offering): void {
            if ($offering->status === self::STATUS_PUBLISHED) {
                $gate = app(OfferingPublishGate::class);
                $gate->assertMayPublish($offering, $gate->isExplicitOverrideActive());
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'offering_capabilities')
            ->withPivot('included');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
