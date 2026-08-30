<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Product;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * WAVE-6 GAP-001 catalog bootstrap + offering assignment (no payment).
 * BK-115 PR-04: publish via OfferingPublishGate — completeness warnings + explicit override.
 */
class ProductCatalogService
{
    public function __construct(
        private readonly AuditLoggerInterface $audit,
        private readonly OfferingPublishGate $publishGate,
    ) {}

    public function ensureDefaultCatalog(): Offering
    {
        return DB::connection('central')->transaction(function () {
            $product = Product::query()->firstOrCreate(
                ['code' => 'jabal'],
                [
                    'name' => 'Jabal',
                    'description' => 'Jabal multi-tenant platform product',
                    'is_active' => true,
                ]
            );

            $capabilities = [
                ['code' => 'user_management', 'name' => 'User Management', 'entitlement_code' => null],
                ['code' => 'mfa', 'name' => 'Multi-Factor Authentication', 'entitlement_code' => 'mfa_available'],
                ['code' => 'sso', 'name' => 'Single Sign-On', 'entitlement_code' => 'sso_available'],
            ];

            $capabilityModels = [];
            foreach ($capabilities as $row) {
                $capabilityModels[$row['code']] = Capability::query()->firstOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'entitlement_code' => $row['entitlement_code'],
                        'is_active' => true,
                    ]
                );
                $product->capabilities()->syncWithoutDetaching([$capabilityModels[$row['code']]->id]);
            }

            $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->first();

            $offering = Offering::query()->firstOrCreate(
                ['code' => 'jabal-standard'],
                [
                    'name' => 'Jabal Standard',
                    'product_id' => $product->id,
                    'plan_id' => $plan?->id,
                    'status' => Offering::STATUS_DRAFT,
                    'version' => 1,
                    'metadata' => ['source' => 'wave6'],
                ]
            );

            $offering->fill([
                'name' => $offering->name ?: 'Jabal Standard',
                'product_id' => $offering->product_id ?: $product->id,
                'plan_id' => $offering->plan_id ?: $plan?->id,
            ]);
            if ($offering->isDirty()) {
                $offering->save();
            }

            $sync = [];
            foreach ($capabilityModels as $cap) {
                $sync[$cap->id] = ['included' => true];
            }
            $offering->capabilities()->sync($sync);
            $offering->refresh();

            if ($offering->status !== Offering::STATUS_PUBLISHED) {
                $this->publish($offering);
            } else {
                // Bootstrap drift: integrity only — do not treat completeness warnings as fatal.
                $report = $this->publishGate->evaluateReport(
                    $offering->fresh(['product', 'plan.entitlements', 'capabilities'])
                );
                if ($report['integrity'] !== []) {
                    $this->publishGate->assertMayPublish($offering);
                }
            }

            return $offering->fresh(['product', 'capabilities', 'plan']);
        });
    }

    /**
     * Authoritative publish transition.
     *
     * Complete → normal publish.
     * Incomplete → requires explicitOverride + auditable actor (Publish Anyway).
     */
    public function publish(
        Offering $offering,
        bool $explicitOverride = false,
        ?string $actorId = null,
        ?string $overrideReason = null,
    ): Offering {
        $offering->loadMissing(['product', 'plan.entitlements', 'capabilities']);
        $report = $this->publishGate->evaluateReport($offering);

        if ($explicitOverride && $report['warnings'] !== []) {
            if (! filled($actorId)) {
                throw new AccessDeniedHttpException(
                    'Explicit Publish Anyway requires an auditable authorized actor_id.'
                );
            }
            $this->assertOverrideCallerAuthorized();
        }

        $this->publishGate->beginPublish($explicitOverride);
        try {
            $this->publishGate->assertMayPublish($offering);

            if ($offering->status === Offering::STATUS_PUBLISHED) {
                return $offering;
            }

            $before = $offering->status;
            $offering->status = Offering::STATUS_PUBLISHED;
            $offering->save();

            $usedOverride = $explicitOverride && $report['warnings'] !== [];

            $this->audit->log($usedOverride ? 'offering.published_with_override' : 'offering.published', [
                'actor_id' => $actorId,
                'auditable_type' => Offering::class,
                'auditable_id' => $offering->id,
                'old_values' => ['status' => $before],
                'new_values' => ['status' => Offering::STATUS_PUBLISHED],
                'metadata' => [
                    'explicit_override' => $usedOverride,
                    'not_recommended' => $report['warnings'] !== [],
                    'recommended' => $report['recommended'],
                    'completeness_warnings' => $report['warnings'],
                    'override_reason' => $usedOverride ? $overrideReason : null,
                ],
            ]);

            return $offering->fresh(['product', 'capabilities', 'plan']);
        } finally {
            $this->publishGate->endPublish();
        }
    }

    public function assignOfferingToTenant(Tenant $tenant, Offering $offering, ?string $actorId = null): Tenant
    {
        if (! $offering->isPublished()) {
            throw new \InvalidArgumentException('Only published offerings may be assigned.');
        }

        $before = $tenant->offering_id;
        $tenant->forceFill(['offering_id' => $offering->id])->save();

        $this->audit->log('tenant.offering_assigned', [
            'tenant_id' => $tenant->id,
            'actor_id' => $actorId,
            'auditable_type' => Tenant::class,
            'auditable_id' => $tenant->id,
            'old_values' => ['offering_id' => $before],
            'new_values' => ['offering_id' => $offering->id],
        ]);

        return $tenant->fresh();
    }

    /**
     * When a Platform user is authenticated, require existing billing manage permission.
     * Seed/system callers with no platform auth may proceed when actor_id is supplied.
     */
    protected function assertOverrideCallerAuthorized(): void
    {
        $platformUser = auth('platform')->user();
        if ($platformUser === null) {
            return;
        }

        if (! method_exists($platformUser, 'hasPlatformPermission')
            || ! $platformUser->hasPlatformPermission('platform.billing.manage')) {
            // Fallback: Spatie-style can() if present on platform user model.
            if (method_exists($platformUser, 'can') && $platformUser->can('platform.billing.manage')) {
                return;
            }

            throw new AccessDeniedHttpException(
                'Explicit Publish Anyway requires platform.billing.manage (existing Platform billing boundary).'
            );
        }
    }
}
