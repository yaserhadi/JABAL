<?php

namespace Tests\Feature\Modules\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Settings\Services\SettingsService;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settingsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = app(SettingsService::class);
    }

    public function test_can_set_and_get_string_setting(): void
    {
        $this->settingsService->set('test.key', 'test value');
        
        $value = $this->settingsService->get('test.key');
        
        $this->assertEquals('test value', $value);
    }

    public function test_can_set_and_get_boolean_setting(): void
    {
        $this->settingsService->set('test.boolean', true, ['type' => 'boolean']);
        
        $value = $this->settingsService->get('test.boolean');
        
        $this->assertTrue($value);
    }

    public function test_can_set_and_get_integer_setting(): void
    {
        $this->settingsService->set('test.number', 42, ['type' => 'integer']);
        
        $value = $this->settingsService->get('test.number');
        
        $this->assertEquals(42, $value);
    }

    public function test_can_set_and_get_json_setting(): void
    {
        $data = ['key' => 'value', 'number' => 123];
        $this->settingsService->set('test.json', $data, ['type' => 'json']);
        
        $value = $this->settingsService->get('test.json');
        
        $this->assertEquals($data, $value);
    }

    public function test_can_check_if_setting_exists(): void
    {
        $this->settingsService->set('test.exists', 'value');
        
        $this->assertTrue($this->settingsService->has('test.exists'));
        $this->assertFalse($this->settingsService->has('test.not.exists'));
    }

    public function test_can_delete_setting(): void
    {
        $this->settingsService->set('test.delete', 'value');
        $this->assertTrue($this->settingsService->has('test.delete'));
        
        $this->settingsService->forget('test.delete');
        
        $this->assertFalse($this->settingsService->has('test.delete'));
    }

    public function test_returns_default_for_missing_setting(): void
    {
        $value = $this->settingsService->get('missing.key', 'default value');
        
        $this->assertEquals('default value', $value);
    }
}
