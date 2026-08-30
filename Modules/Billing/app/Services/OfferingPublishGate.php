<?php

namespace Modules\Billing\Services;

use Modules\Billing\Exceptions\OfferingPublishBlockedException;
use Modules\Billing\Exceptions\OfferingPublishOverrideRequiredException;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Tenancy\Models\SetupDefinition;

/**
 * BK-115 PR-04: Offering publish decision support at the domain boundary.
 *
 * Completeness → warnings / NOT RECOMMENDED / explicit override allowed.
 * Integrity → non-overridable ONLY when publication would create an invalid Product graph.
 *
 * Does not invent channel taxonomy. Localization applies only when locales are claimed.
 */
class OfferingPublishGate
{
    /** Request/process-scoped so ProductCatalogService and Eloquent hooks share one context. */
    private static bool $explicitOverrideActive = false;

    public function beginPublish(bool $explicitOverride = false): void
    {
        self::$explicitOverrideActive = $explicitOverride;
    }

    public function endPublish(): void
    {
        self::$explicitOverrideActive = false;
    }

    public function isExplicitOverrideActive(): bool
    {
        return self::$explicitOverrideActive;
    }

    /**
     * @return array{
     *   integrity: list<array{lane: string, code: string, message: string}>,
     *   warnings: list<array{lane: string, code: string, message: string}>,
     *   complete: bool,
     *   recommended: bool
     * }
     */
    public function evaluateReport(Offering $offering): array
    {
        $offering->loadMissing(['product', 'plan.entitlements', 'capabilities']);

        $integrity = $this->integrityFailures($offering);
        $warnings = [];
        $warnings = array_merge($warnings, $this->commercialWarnings($offering));
        $warnings = array_merge($warnings, $this->technicalWarnings($offering));
        $warnings = array_merge($warnings, $this->localizationWarnings($offering));
        $warnings = array_merge($warnings, $this->blockingCapabilityWarnings($offering));

        $complete = $integrity === [] && $warnings === [];

        return [
            'integrity' => $integrity,
            'warnings' => $warnings,
            'complete' => $complete,
            'recommended' => $complete,
        ];
    }

    /**
     * Backward-compatible flat list: integrity first, then completeness warnings.
     *
     * @return list<array{lane: string, code: string, message: string}>
     */
    public function evaluate(Offering $offering): array
    {
        $report = $this->evaluateReport($offering);

        return array_merge($report['integrity'], $report['warnings']);
    }

    /**
     * Enforce publish contract for the active publish context (see beginPublish).
     */
    public function assertMayPublish(Offering $offering, ?bool $explicitOverride = null): void
    {
        $override = $explicitOverride ?? self::$explicitOverrideActive;
        $report = $this->evaluateReport($offering);

        if ($report['integrity'] !== []) {
            throw OfferingPublishBlockedException::fromFailures($report['integrity']);
        }

        if ($report['warnings'] !== [] && ! $override) {
            throw OfferingPublishOverrideRequiredException::fromWarnings($report['warnings']);
        }
    }

    /**
     * Structural only: Product FK/graph required for a coherent Offering.
     * Evidence: WAVE-6 offerings.product_id → products (CASCADE); capability evaluation assumes Product.
     *
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function integrityFailures(Offering $offering): array
    {
        if (! $offering->product_id || ! $offering->product) {
            return [[
                'lane' => 'integrity',
                'code' => 'integrity_product_missing',
                'message' => 'Offering must reference an existing Product; publication without Product is structurally invalid.',
            ]];
        }

        return [];
    }

    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function commercialWarnings(Offering $offering): array
    {
        $warnings = [];

        if (! filled($offering->code) || ! filled($offering->name)) {
            $warnings[] = [
                'lane' => 'commercial',
                'code' => 'commercial_identity_incomplete',
                'message' => 'Offering code and name are incomplete for Commercial Completeness.',
            ];
        }

        if ($offering->product && ! $offering->product->is_active) {
            $warnings[] = [
                'lane' => 'commercial',
                'code' => 'commercial_product_inactive',
                'message' => 'Offering Product is inactive (not recommended).',
            ];
        }

        if (! $offering->plan_id || ! $offering->plan) {
            $warnings[] = [
                'lane' => 'commercial',
                'code' => 'commercial_plan_missing',
                'message' => 'Offering has no Plan (sellable commercial definition incomplete).',
            ];
        } elseif (! $offering->plan->is_active) {
            $warnings[] = [
                'lane' => 'commercial',
                'code' => 'commercial_plan_inactive',
                'message' => 'Offering Plan is inactive (not recommended).',
            ];
        }

        return $warnings;
    }

    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function technicalWarnings(Offering $offering): array
    {
        $warnings = [];
        $product = $offering->product;
        if (! $product) {
            return $warnings;
        }

        $productCapIds = $product->capabilities()->pluck('capabilities.id')->all();
        $included = $offering->capabilities->filter(fn (Capability $c) => (bool) ($c->pivot->included ?? true));

        foreach ($included as $cap) {
            if (! $cap->is_active) {
                $warnings[] = [
                    'lane' => 'technical',
                    'code' => 'technical_capability_inactive',
                    'message' => "Included Capability [{$cap->code}] is inactive.",
                ];
            }
            if (! in_array($cap->id, $productCapIds, true)) {
                $warnings[] = [
                    'lane' => 'technical',
                    'code' => 'technical_capability_not_on_product',
                    'message' => "Included Capability [{$cap->code}] is not attached to the Offering Product.",
                ];
            }
        }

        $deps = $offering->metadata['capability_dependencies'] ?? null;
        if (is_array($deps)) {
            $includedCodes = $included->pluck('code')->all();
            foreach ($deps as $capCode => $requires) {
                if (! is_string($capCode) || ! in_array($capCode, $includedCodes, true)) {
                    continue;
                }
                $requires = is_array($requires) ? $requires : [];
                foreach ($requires as $need) {
                    if (! is_string($need)) {
                        continue;
                    }
                    if (! in_array($need, $includedCodes, true)) {
                        $warnings[] = [
                            'lane' => 'technical',
                            'code' => 'technical_capability_dependency_unmet',
                            'message' => "Capability [{$capCode}] requires [{$need}] which is not included on the Offering.",
                        ];
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function localizationWarnings(Offering $offering): array
    {
        $meta = is_array($offering->metadata) ? $offering->metadata : [];
        $claimed = $meta['claimed_locales'] ?? $meta['supported_locales'] ?? null;
        if (! is_array($claimed) || $claimed === []) {
            return [];
        }

        $completeness = $meta['locale_completeness'] ?? [];
        if (! is_array($completeness)) {
            $completeness = [];
        }

        $warnings = [];
        foreach ($claimed as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }
            $ok = $completeness[$locale] ?? false;
            if ($ok !== true && $ok !== 'complete') {
                $warnings[] = [
                    'lane' => 'localization',
                    'code' => 'localization_claimed_locale_incomplete',
                    'message' => "Claimed locale [{$locale}] is not marked complete in Offering metadata.",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Blocking Capability packaging gaps are completeness warnings (overridable), not integrity.
     *
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function blockingCapabilityWarnings(Offering $offering): array
    {
        $warnings = [];
        $plan = $offering->plan;
        $includedCodes = $offering->capabilities
            ->filter(fn (Capability $c) => (bool) ($c->pivot->included ?? true))
            ->pluck('code')
            ->all();

        $blockingDefs = SetupDefinition::query()
            ->where('is_active', true)
            ->where('requirement_type', SetupDefinition::TYPE_BLOCKING)
            ->whereNotNull('capability_code')
            ->where('capability_code', '!=', '')
            ->get();

        foreach ($blockingDefs as $def) {
            $capCode = (string) $def->capability_code;
            if (! in_array($capCode, $includedCodes, true)) {
                $warnings[] = [
                    'lane' => 'blocking_capability',
                    'code' => 'blocking_capability_not_on_offering',
                    'message' => "Blocking Capability [{$capCode}] (setup [{$def->code}]) is not included on the Offering.",
                ];

                continue;
            }

            $cap = $offering->capabilities->firstWhere('code', $capCode);
            $entitlement = $cap?->entitlement_code;
            if (! filled($entitlement)) {
                continue;
            }

            if (! $plan instanceof Plan) {
                $warnings[] = [
                    'lane' => 'blocking_capability',
                    'code' => 'blocking_capability_plan_missing',
                    'message' => "Blocking Capability [{$capCode}] expects Plan entitlement [{$entitlement}] but Offering has no Plan.",
                ];

                continue;
            }

            $hasEnt = $plan->entitlements
                ->where('code', $entitlement)
                ->where('is_active', true)
                ->isNotEmpty();

            if (! $hasEnt) {
                $warnings[] = [
                    'lane' => 'blocking_capability',
                    'code' => 'blocking_capability_unavailable_in_plan',
                    'message' => "Blocking Capability [{$capCode}] expects entitlement [{$entitlement}] which is unavailable on Plan [{$plan->code}].",
                ];
            }
        }

        return $warnings;
    }
}
