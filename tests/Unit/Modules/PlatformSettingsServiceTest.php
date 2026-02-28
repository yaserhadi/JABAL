<?php

namespace Tests\Unit\Modules;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Settings\Services\PlatformSettingsRepository;
use Modules\Settings\Services\PlatformSettingsService;
use Tests\TestCase;

class PlatformSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SettingsRepositoryInterface::class, PlatformSettingsRepository::class);
    }

    public function test_get_and_set_work(): void
    {
        $service = app(PlatformSettingsService::class);

        $this->assertNull($service->get('test_key'));
        $this->assertEquals('default', $service->get('test_key', 'default'));

        $service->set('test_key', 'test_value');

        $this->assertEquals('test_value', $service->get('test_key'));
    }

    public function test_has_and_forget_work(): void
    {
        $service = app(PlatformSettingsService::class);

        $this->assertFalse($service->has('test_key'));
        $service->set('test_key', 'value');
        $this->assertTrue($service->has('test_key'));
        $service->forget('test_key');
        $this->assertFalse($service->has('test_key'));
    }
}
