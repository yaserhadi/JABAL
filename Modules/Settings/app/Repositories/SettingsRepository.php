<?php

namespace Modules\Settings\Repositories;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\Events\SettingUpdated;
use Modules\Settings\Models\PlatformSetting;

class SettingsRepository implements SettingsRepositoryInterface
{
    private const CACHE_PREFIX = 'platform_setting:';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get a setting value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            $setting = PlatformSetting::where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            return $setting->getDecodedValue();
        });
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, string $type = 'string', string $group = 'general', bool $isEncrypted = false): void
    {
        $oldValue = $this->get($key);

        $setting = PlatformSetting::firstOrNew(['key' => $key]);
        $setting->type = $type;
        $setting->group = $group;
        $setting->is_encrypted = $isEncrypted;
        $setting->setEncodedValue($value);
        $setting->save();

        // Invalidate cache (only clear cache, don't delete the record)
        Cache::forget(self::CACHE_PREFIX.$key);

        // Dispatch event
        event(new SettingUpdated($group, $key, $oldValue, $value));
    }

    /**
     * Check if a setting exists.
     */
    public function has(string $key): bool
    {
        return PlatformSetting::where('key', $key)->exists();
    }

    /**
     * Delete a setting.
     */
    public function forget(string $key): void
    {
        PlatformSetting::where('key', $key)->delete();
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * Get all settings in a group.
     */
    public function getGroup(string $group): array
    {
        $settings = PlatformSetting::where('group', $group)->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = $setting->getDecodedValue();
        }

        return $result;
    }

    /**
     * Flush all settings cache.
     */
    public function flushCache(): void
    {
        Cache::flush();
    }
}
