<?php

namespace Tests\Feature\Modules\Billing;

use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Exceptions\OfferingPublishBlockedException;
use Modules\Billing\Exceptions\OfferingPublishOverrideRequiredException;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\OfferingPublishGate;
use Modules\Billing\Services\ProductCatalogService;
use Modules\Tenancy\Models\SetupDefinition;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/** BK-115 PR-04: completeness warnings + explicit Publish Anyway (not unconditional HARD BLOCK). */
class OfferingPublishGateTest extends TestCase
{
    #[Test]
    public function complete_offering_normal_publish_succeeds(): void
    {
        $offering = $this->makeCompleteDraftOffering();

        $published = app(ProductCatalogService::class)->publish($offering);
        $this->assertTrue($published->isPublished());

        $report = app(OfferingPublishGate::class)->evaluateReport($published);
        $this->assertTrue($report['complete']);
        $this->assertTrue($report['recommended']);
        $this->assertSame([], $report['warnings']);
    }

    #[Test]
    public function incomplete_offering_returns_warnings_and_not_recommended(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-w-'.uniqid(),
            'name' => 'Warn Product',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'code' => 'pr04-w-off-'.uniqid(),
            'name' => 'Incomplete',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $report = app(OfferingPublishGate::class)->evaluateReport($offering);
        $this->assertFalse($report['complete']);
        $this->assertFalse($report['recommended']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertContains('commercial', array_column($report['warnings'], 'lane'));
        $this->assertSame([], $report['integrity']);
    }

    #[Test]
    public function incomplete_without_explicit_override_does_not_silently_publish(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-noov-'.uniqid(),
            'name' => 'No Override Product',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'code' => 'pr04-noov-off-'.uniqid(),
            'name' => 'Needs Plan',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        try {
            app(ProductCatalogService::class)->publish($offering);
            $this->fail('Expected OfferingPublishOverrideRequiredException');
        } catch (OfferingPublishOverrideRequiredException $e) {
            $this->assertNotEmpty($e->warnings);
            $this->assertStringContainsString('NOT RECOMMENDED', $e->getMessage());
        }

        $this->assertSame(Offering::STATUS_DRAFT, $offering->fresh()->status);
    }

    #[Test]
    public function authorized_explicit_publish_anyway_succeeds_and_audits_override(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-ov-'.uniqid(),
            'name' => 'Override Product',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'code' => 'pr04-ov-off-'.uniqid(),
            'name' => 'Publish Anyway',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $actor = (string) Str::uuid();
        $published = app(ProductCatalogService::class)->publish(
            $offering,
            explicitOverride: true,
            actorId: $actor,
            overrideReason: 'Owner-authorized lab publish',
        );

        $this->assertTrue($published->isPublished());

        $log = AuditLog::query()
            ->where('event', 'offering.published_with_override')
            ->where('auditable_id', $published->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log, 'Override publish must write offering.published_with_override audit');
        $this->assertSame($actor, $log->actor_id);
        $meta = is_array($log->metadata) ? $log->metadata : [];
        $this->assertTrue((bool) ($meta['explicit_override'] ?? false));
        $this->assertNotEmpty($meta['completeness_warnings'] ?? []);
        $this->assertSame('Owner-authorized lab publish', $meta['override_reason'] ?? null);
    }

    #[Test]
    public function correcting_incomplete_name_then_normal_publish_without_override(): void
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-ok-'.uniqid(),
            'name' => 'OK Product',
            'is_active' => true,
        ]);
        $cap = Capability::query()->create([
            'code' => 'pr04-ok-cap-'.uniqid(),
            'name' => 'OK Cap',
            'is_active' => true,
        ]);
        $product->capabilities()->attach($cap->id);

        $offering = Offering::query()->create([
            'code' => 'pr04-ok-off-'.uniqid(),
            'name' => '',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);
        $offering->capabilities()->attach($cap->id, ['included' => true]);

        try {
            app(ProductCatalogService::class)->publish($offering->fresh());
            $this->fail('Expected override required for empty name');
        } catch (OfferingPublishOverrideRequiredException $e) {
            $this->assertContains('commercial', array_column($e->warnings, 'lane'));
        }

        $offering->name = 'Corrected Offering';
        $offering->save();
        $published = app(ProductCatalogService::class)->publish($offering->fresh(['product', 'plan', 'capabilities']));
        $this->assertTrue($published->isPublished());
    }

    #[Test]
    public function override_without_actor_id_is_denied(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-unauth-'.uniqid(),
            'name' => 'Unauth Product',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'code' => 'pr04-unauth-off-'.uniqid(),
            'name' => 'No Actor',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        app(ProductCatalogService::class)->publish($offering, explicitOverride: true, actorId: null);
    }

    #[Test]
    public function model_status_bypass_cannot_silently_publish_incomplete(): void
    {
        $product = Product::query()->create([
            'code' => 'pr04-bypass-'.uniqid(),
            'name' => 'Bypass Product',
            'is_active' => true,
        ]);
        $bypass = Offering::query()->create([
            'code' => 'pr04-bypass-off-'.uniqid(),
            'name' => '',
            'product_id' => $product->id,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $this->expectException(OfferingPublishOverrideRequiredException::class);
        $bypass->status = Offering::STATUS_PUBLISHED;
        $bypass->save();
    }

    #[Test]
    public function missing_product_remains_integrity_hard_block(): void
    {
        $offering = new Offering([
            'code' => 'pr04-int-'.uniqid(),
            'name' => 'No Product',
            'product_id' => null,
            'plan_id' => null,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);

        $report = app(OfferingPublishGate::class)->evaluateReport($offering);
        $this->assertNotEmpty($report['integrity']);
        $this->assertSame('integrity_product_missing', $report['integrity'][0]['code']);

        $this->expectException(OfferingPublishBlockedException::class);
        app(OfferingPublishGate::class)->assertMayPublish($offering, true);
    }

    #[Test]
    public function localization_and_technical_are_warnings_not_integrity(): void
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-loc-'.uniqid(),
            'name' => 'Loc Product',
            'is_active' => true,
        ]);
        $orphan = Capability::query()->create([
            'code' => 'pr04-orphan-'.uniqid(),
            'name' => 'Orphan',
            'is_active' => true,
        ]);
        $offering = Offering::query()->create([
            'code' => 'pr04-loc-off-'.uniqid(),
            'name' => 'Loc Tech',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
            'metadata' => [
                'claimed_locales' => ['ar'],
                'locale_completeness' => ['ar' => false],
            ],
        ]);
        $offering->capabilities()->attach($orphan->id, ['included' => true]);

        $report = app(OfferingPublishGate::class)->evaluateReport($offering->fresh(['product', 'plan', 'capabilities']));
        $this->assertSame([], $report['integrity']);
        $lanes = array_column($report['warnings'], 'lane');
        $this->assertContains('localization', $lanes);
        $this->assertContains('technical', $lanes);

        app(ProductCatalogService::class)->publish(
            $offering->fresh(['product', 'plan', 'capabilities']),
            explicitOverride: true,
            actorId: (string) Str::uuid(),
        );
        $this->assertTrue($offering->fresh()->isPublished());
    }

    #[Test]
    public function blocking_capability_packaging_is_warning_and_overridable(): void
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

        $report = app(OfferingPublishGate::class)->evaluateReport(
            $offering->fresh(['product', 'plan.entitlements', 'capabilities'])
        );
        $this->assertSame([], $report['integrity']);
        $this->assertContains('blocking_capability', array_column($report['warnings'], 'lane'));

        app(ProductCatalogService::class)->publish(
            $offering->fresh(['product', 'plan.entitlements', 'capabilities']),
            explicitOverride: true,
            actorId: (string) Str::uuid(),
        );
        $this->assertTrue($offering->fresh()->isPublished());
    }

    #[Test]
    public function ensure_default_catalog_still_publishes_valid_offering(): void
    {
        $offering = app(ProductCatalogService::class)->ensureDefaultCatalog();
        $this->assertTrue($offering->isPublished());
        $report = app(OfferingPublishGate::class)->evaluateReport($offering);
        $this->assertTrue($report['complete']);
    }

    protected function makeCompleteDraftOffering(): Offering
    {
        $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
        $product = Product::query()->create([
            'code' => 'pr04-complete-'.uniqid(),
            'name' => 'Complete Product',
            'is_active' => true,
        ]);
        $cap = Capability::query()->create([
            'code' => 'pr04-complete-cap-'.uniqid(),
            'name' => 'Complete Cap',
            'is_active' => true,
        ]);
        $product->capabilities()->attach($cap->id);

        $offering = Offering::query()->create([
            'code' => 'pr04-complete-off-'.uniqid(),
            'name' => 'Complete Offering',
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => Offering::STATUS_DRAFT,
            'version' => 1,
        ]);
        $offering->capabilities()->attach($cap->id, ['included' => true]);

        return $offering->fresh(['product', 'plan', 'capabilities']);
    }
}
