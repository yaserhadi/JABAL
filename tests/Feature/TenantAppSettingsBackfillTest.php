<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\AppSetting;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetting;
use Modules\Tenancy\Services\TenantAppSettingsBackfill;
use Modules\Tenancy\Services\TenantSettingsService;
use Tests\TestCase;

/**
 * BK-028: Backfill parity from central tenant_settings to tenant app_settings.
 */
class TenantAppSettingsBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_copies_central_row_to_app_settings_with_field_parity(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->createPersonalTenant($owner);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Acme',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'branding_logo_url' => 'https://example.com/logo.png',
            'member_removal_mode' => 'reversible',
        ]);

        $backfill = app(TenantAppSettingsBackfill::class);
        $this->assertSame('backfilled', $backfill->backfillTenant($tenant));

        $parity = $backfill->verifyTenantParity($tenant);
        $this->assertTrue($parity['ok'], implode(', ', $parity['mismatches']));

        tenancy()->initialize($tenant);
        $row = AppSetting::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Acme', $row->display_name);
        $this->assertSame('Africa/Cairo', $row->timezone);
        $this->assertSame('reversible', $row->member_removal_mode);
    }

    public function test_backfill_is_idempotent_when_parity_already_exists(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->createPersonalTenant($owner);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'member_removal_mode' => 'permanent',
        ]);

        $backfill = app(TenantAppSettingsBackfill::class);
        $this->assertSame('backfilled', $backfill->backfillTenant($tenant));
        $this->assertSame('skipped_already_parity', $backfill->backfillTenant($tenant));
    }

    public function test_backfill_skips_when_no_central_row(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->createPersonalTenant($owner);

        $backfill = app(TenantAppSettingsBackfill::class);
        $this->assertSame('skipped_no_central_row', $backfill->backfillTenant($tenant));
        $this->assertTrue($backfill->verifyTenantParity($tenant)['ok']);
    }

    public function test_service_uses_app_settings_after_backfill(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->createPersonalTenant($owner);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'member_removal_mode' => 'reversible',
        ]);

        app(TenantAppSettingsBackfill::class)->backfillTenant($tenant);

        $this->assertSame('reversible', app(TenantSettingsService::class)->memberRemovalMode($tenant));
    }

    public function test_invalid_central_mode_still_resolves_permanent_after_backfill(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->createPersonalTenant($owner);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'member_removal_mode' => 'legacy_soft_delete',
        ]);

        app(TenantAppSettingsBackfill::class)->backfillTenant($tenant);

        $this->assertSame('permanent', app(TenantSettingsService::class)->memberRemovalMode($tenant));
    }
}
