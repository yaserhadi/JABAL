<?php

namespace Tests\Feature\Modules\Billing;

use Modules\Billing\Exceptions\OfferingPublishBlockedException;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\OfferingPublishGate;
use Modules\Billing\Services\ProductCatalogService;
use Modules\Tenancy\Models\SetupDefinition;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-115 PR-04: Offering publish HARD BLOCK at domain boundary. */
class OfferingPublishGateTest extends TestCase
{
    #[Test]
    public function incomplete_commercial_hard_blocks_publish(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-comm-'.uniqid(),
            'name' => 'PR04 Commercial',
            'is_active' => true,
        ]);

        $offering = Offering::query()->create([
            'code' => 'pr04-c-'.uniqid(),
            'name' => 'Incomplete commercial',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $this->expectException(OfferingPublishBlockedException::class);
        app(ProductCatalogService::class)->publish($offering);
    }

    #[Test]
    public function incomplete_technical_hard_blocks_publish(): void
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-tech-p-'.uniqid(),
            'name' => 'PR04 Tech Product',
            'is_active' => true,
        ]);
        $orphanCap = Capability::query()->create([
            'code' => 'pr04-orphan-'.uniqid(),
            'name' => 'Orphan Cap',
            'is_active' => true,
        ]);

        $offering = Offering::query()->create([
            'code' => 'pr04-t-'.uniqid(),
            'name' => 'Tech incomplete',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);
        $offering->capabilities()->attach($orphanCap->id, ['included' => true]);

        try {
            app(ProductCatalogService::class)->publish($offering->fresh(['product', 'plan', 'capabilities']));
            $this->fail('Expected OfferingPublishBlockedException');
        } catch (OfferingPublishBlockedException $e) {
            $lanes = array_column($e->failures, 'lane');
            $this->assertContains('technical', $lanes);
        }
    }

    #[Test]
    public function incomplete_localization_hard_blocks_when_locale_claimed(): void
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-loc-p-'.uniqid(),
            'name' => 'PR04 Loc Product',
            'is_active' => true,
        ]);

        $offering = Offering::query()->create([
            'code' => 'pr04-l-'.uniqid(),
            'name' => 'Loc incomplete',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
            'metadata' => [
                'claimed_locales' => ['ar'],
                'locale_completeness' => ['ar' => false],
            ],
        ]);

        try {
            app(ProductCatalogService::class)->publish($offering);
            $this->fail('Expected OfferingPublishBlockedException');
        } catch (OfferingPublishBlockedException $e) {
            $lanes = array_column($e->failures, 'lane');
            $this->assertContains('localization', $lanes);
        }
    }

    #[Test]
    public function blocking_capability_unavailable_hard_blocks_publish(): void
    {
        $plan = Plan::query()->create([
            'code' => 'pr04-plan-'.uniqid(),
            'name' => 'PR04 Plan No Ent',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'code' => 'pr04-blk-p-'.uniqid(),
            'name' => 'PR04 Blocking Product',
            'is_active' => true,
        ]);
        $cap = Capability::query()->create([
            'code' => 'pr04-block-cap-'.uniqid(),
            'name' => 'Blocking Cap',
            'entitlement_code' => 'pr04_block_ent_'.uniqid(),
            'is_active' => true,
        ]);
        $product->capabilities()->attach($cap->id);

        SetupDefinition::query()->create([
            'code' => 'pr04_blocking_'.uniqid(),
            'version' => 1,
            'title' => 'PR04 Blocking Cap Def',
            'requirement_type' => SetupDefinition::TYPE_BLOCKING,
            'capability_code' => $cap->code,
            'is_active' => true,
        ]);

        $offering = Offering::query()->create([
            'code' => 'pr04-b-'.uniqid(),
            'name' => 'Blocking incomplete',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);
        $offering->capabilities()->attach($cap->id, ['included' => true]);

        try {
            app(ProductCatalogService::class)->publish($offering->fresh(['product', 'plan.entitlements', 'capabilities']));
            $this->fail('Expected OfferingPublishBlockedException');
        } catch (OfferingPublishBlockedException $e) {
            $lanes = array_column($e->failures, 'lane');
            $this->assertContains('blocking_capability', $lanes);
        }
    }

    #[Test]
    public function correction_allows_valid_publish_and_model_bypass_is_blocked(): void
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-ok-p-'.uniqid(),
            'name' => 'PR04 OK Product',
            'is_active' => true,
        ]);
        $cap = Capability::query()->create([
            'code' => 'pr04-ok-cap-'.uniqid(),
            'name' => 'OK Cap',
            'is_active' => true,
        ]);
        $product->capabilities()->attach($cap->id);

        $offering = Offering::query()->create([
            'code' => 'pr04-ok-'.uniqid(),
            'name' => '',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);
        $offering->capabilities()->attach($cap->id, ['included' => true]);

        try {
            app(ProductCatalogService::class)->publish($offering->fresh());
            $this->fail('Expected OfferingPublishBlockedException for empty name');
        } catch (OfferingPublishBlockedException $e) {
            $this->assertContains('commercial', array_column($e->failures, 'lane'));
        }

        $offering->name = 'Corrected Offering';
        $offering->save();
        $published = app(ProductCatalogService::class)->publish($offering->fresh(['product', 'plan', 'capabilities']));
        $this->assertTrue($published->isPublished());

        $bypass = Offering::query()->create([
            'code' => 'pr04-bypass-'.uniqid(),
            'name' => '',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $this->expectException(OfferingPublishBlockedException::class);
        $bypass->status = Offering::STATUS_PUBLISHED;
        $bypass->save();
    }

    #[Test]
    public function ensure_default_catalog_still_publishes_valid_offering(): void
    {
        $offering = app(ProductCatalogService::class)->ensureDefaultCatalog();
        $this->assertTrue($offering->isPublished());
        $this->assertSame([], app(OfferingPublishGate::class)->evaluate($offering));
    }
}
