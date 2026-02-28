<?php

namespace Modules\Settings\Repositories;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Modules\Settings\Models\PlatformSettings;

/**
 * Platform Settings Repository.
 *
 * Implements the settings repository interface with caching and tag-based invalidation.
 * Supports 'group.key' format for keys, splitting on the first dot.
 */
class PlatformSettingsRepository implements SettingsRepositoryInterface
{
    /**
     * Cache tag for settings invalidation.
     */
    private const CACHE_TAG = 'settings';

    /**
     * Default group name.
     */
    private const DEFAULT_GROUP = 'general';

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * Get a setting value by key.
     *
     * Key format: 'group.key' or 'key' (defaults to 'general' group)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$group, $settingKey] = $this->parseKey($key);

        $cacheKey = $this->getCacheKey($group, $settingKey);

        $setting = Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn () => PlatformSettings::where('group', $group)
                ->where('key', $settingKey)
                ->first()
        );

        if (! $setting) {
            return $default;
        }

        return $setting->getValue();
    }

    /**
     * Set a setting value.
     *
     * Key format: 'group.key' or 'key' (defaults to 'general' group)
     */
    public function set(string $key, mixed $value): void
    {
        [$group, $settingKey] = $this->parseKey($key);

        $setting = PlatformSettings::where('group', $group)
            ->where('key', $settingKey)
            ->first();

        $encrypt = $this->shouldEncrypt($settingKey);

        if ($setting) {
            $setting->setValue($value, $encrypt);
            $setting->save();
        } else {
            $setting = new PlatformSettings([
                'group' => $group,
                'key' => $settingKey,
            ]);
            $setting->setValue($value, $encrypt);
            $setting->save();
        }

        $this->invalidateCache($group, $settingKey);
    }

    /**
     * Remove a setting.
     *
     * Key format: 'group.key' or 'key' (defaults to 'general' group)
     */
    public function forget(string $key): void
    {
        [$group, $settingKey] = $this->parseKey($key);

        PlatformSettings::where('group', $group)
            ->where('key', $settingKey)
            ->delete();

        $this->invalidateCache($group, $settingKey);
    }

    /**
     * Check if a setting exists.
     *
     * Key format: 'group.key' or 'key' (defaults to 'general' group)
     */
    public function has(string $key): bool
    {
        [$group, $settingKey] = $this->parseKey($key);

        $cacheKey = $this->getCacheKey($group, $settingKey);

        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey.':exists',
            self::CACHE_TTL,
            fn () => PlatformSettings::where('group', $group)
                ->where('key', $settingKey)
                ->exists()
        );
    }

    /**
     * Get all settings in a group.
     *
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $cacheKey = $this->getGroupCacheKey($group);

        return Cache::tags([self::CACHE_TAG])->remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($group) {
                return PlatformSettings::where('group', $group)
                    ->get()
                    ->mapWithKeys(function (PlatformSettings $setting) {
                        return [$setting->key => $setting->getValue()];
                    })
                    ->all();
            }
        );
    }

    /**
     * Parse a key into group and setting key.
     *
     * Format: 'group.key' splits on first dot
     * If no dot, defaults to 'general' group
     *
     * @return array{0: string, 1: string}
     */
    private function parseKey(string $key): array
    {
        $dotPosition = strpos($key, '.');

        if ($dotPosition === false) {
            return [self::DEFAULT_GROUP, $key];
        }

        $group = substr($key, 0, $dotPosition);
        $settingKey = substr($key, $dotPosition + 1);

        return [$group ?: self::DEFAULT_GROUP, $settingKey];
    }

    /**
     * Get cache key for a specific setting.
     */
    private function getCacheKey(string $group, string $key): string
    {
        return "settings:{$group}:{$key}";
    }

    /**
     * Get cache key for a group.
     */
    private function getGroupCacheKey(string $group): string
    {
        return "settings:group:{$group}";
    }

    /**
     * Invalidate cache for a setting or group.
     */
    private function invalidateCache(string $group, ?string $key = null): void
    {
        // Invalidate group cache
        Cache::tags([self::CACHE_TAG])->forget($this->getGroupCacheKey($group));

        // Invalidate specific setting cache if key provided
        if ($key !== null) {
            Cache::tags([self::CACHE_TAG])->forget($this->getCacheKey($group, $key));
            Cache::tags([self::CACHE_TAG])->forget($this->getCacheKey($group, $key).':exists');
        } else {
            // If no key, flush all settings cache (nuclear option)
            Cache::tags([self::CACHE_TAG])->flush();
        }
    }

    /**
     * Determine if a setting key should be encrypted.
     */
    private function shouldEncrypt(string $key): bool
    {
        if (! config('settings.encrypt_sensitive_keys', false)) {
            return false;
        }

        $sensitiveKeywords = ['password', 'secret', 'api_key', 'token', 'credential'];

        $lowerKey = strtolower($key);

        foreach ($sensitiveKeywords as $keyword) {
            if (str_contains($lowerKey, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
