<?php

namespace Modules\Billing\Services;

use Modules\Billing\Exceptions\OfferingPublishBlockedException;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Tenancy\Models\SetupDefinition;

/**
 * BK-115 PR-04: Authoritative Offering publish HARD BLOCK (Frozen PR §10.1–10.2).
 *
 * Domain boundary — all transitions to published must pass assertMayPublish.
 * Does not invent channel taxonomy. Localization applies only when locales are claimed.
 */
class OfferingPublishGate
{
    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    public function evaluate(Offering $offering): array
    {
        $offering->loadMissing(['product', 'plan.entitlements', 'capabilities']);

        $failures = [];

        $failures = array_merge($failures, $this->commercialFailures($offering));
        $failures = array_merge($failures, $this->technicalFailures($offering));
        $failures = array_merge($failures, $this->localizationFailures($offering));
        $failures = array_merge($failures, $this->blockingCapabilityFailures($offering));

        return $failures;
    }

    public function assertMayPublish(Offering $offering): void
    {
        $failures = $this->evaluate($offering);
        if ($failures !== []) {
            throw OfferingPublishBlockedException::fromFailures($failures);
        }
    }

    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function commercialFailures(Offering $offering): array
    {
        $failures = [];

        if (! filled($offering->code) || ! filled($offering->name)) {
            $failures[] = [
                'lane' => 'commercial',
                'code' => 'commercial_identity_incomplete',
                'message' => 'Offering code and name are required for Commercial Completeness.',
            ];
        }

        if (! $offering->product_id || ! $offering->product) {
            $failures[] = [
                'lane' => 'commercial',
                'code' => 'commercial_product_missing',
                'message' => 'Offering must reference an existing Product.',
            ];
        } elseif (! $offering->product->is_active) {
            $failures[] = [
                'lane' => 'commercial',
                'code' => 'commercial_product_inactive',
                'message' => 'Offering Product must be active.',
            ];
        }

        if (! $offering->plan_id || ! $offering->plan) {
            $failures[] = [
                'lane' => 'commercial',
                'code' => 'commercial_plan_missing',
                'message' => 'Offering must reference an existing Plan (sellable commercial definition).',
            ];
        } elseif (! $offering->plan->is_active) {
            $failures[] = [
                'lane' => 'commercial',
                'code' => 'commercial_plan_inactive',
                'message' => 'Offering Plan must be active.',
            ];
        }

        return $failures;
    }

    /**
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function technicalFailures(Offering $offering): array
    {
        $failures = [];
        $product = $offering->product;
        if (! $product) {
            return $failures;
        }

        $productCapIds = $product->capabilities()->pluck('capabilities.id')->all();
        $included = $offering->capabilities->filter(fn (Capability $c) => (bool) ($c->pivot->included ?? true));

        foreach ($included as $cap) {
            if (! $cap->is_active) {
                $failures[] = [
                    'lane' => 'technical',
                    'code' => 'technical_capability_inactive',
                    'message' => "Included Capability [{$cap->code}] is inactive.",
                ];
            }
            if (! in_array($cap->id, $productCapIds, true)) {
                $failures[] = [
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
                        $failures[] = [
                            'lane' => 'technical',
                            'code' => 'technical_capability_dependency_unmet',
                            'message' => "Capability [{$capCode}] requires [{$need}] which is not included on the Offering.",
                        ];
                    }
                }
            }
        }

        return $failures;
    }

    /**
     * Localization Completeness applies only to locales the Offering claims to support.
     *
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function localizationFailures(Offering $offering): array
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

        $failures = [];
        foreach ($claimed as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }
            $ok = $completeness[$locale] ?? false;
            if ($ok !== true && $ok !== 'complete') {
                $failures[] = [
                    'lane' => 'localization',
                    'code' => 'localization_claimed_locale_incomplete',
                    'message' => "Claimed locale [{$locale}] is not marked complete in Offering metadata.",
                ];
            }
        }

        return $failures;
    }

    /**
     * Product Blocking Capability unavailable on Plan/Offering → BLOCK PUBLISH (Frozen §10.2).
     *
     * Uses active Setup Definitions of type blocking with a capability_code
     * (Product Setup Definition → Capability link already in WAVE-6).
     *
     * @return list<array{lane: string, code: string, message: string}>
     */
    protected function blockingCapabilityFailures(Offering $offering): array
    {
        $failures = [];
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
                $failures[] = [
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
                $failures[] = [
                    'lane' => 'blocking_capability',
                    'code' => 'blocking_capability_plan_missing',
                    'message' => "Blocking Capability [{$capCode}] requires Plan entitlement [{$entitlement}] but Offering has no Plan.",
                ];

                continue;
            }

            $hasEnt = $plan->entitlements
                ->where('code', $entitlement)
                ->where('is_active', true)
                ->isNotEmpty();

            if (! $hasEnt) {
                $failures[] = [
                    'lane' => 'blocking_capability',
                    'code' => 'blocking_capability_unavailable_in_plan',
                    'message' => "Blocking Capability [{$capCode}] requires entitlement [{$entitlement}] which is unavailable on Plan [{$plan->code}].",
                ];
            }
        }

        return $failures;
    }
}
