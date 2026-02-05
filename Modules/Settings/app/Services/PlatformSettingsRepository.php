<?php

namespace Modules\Settings\Services;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Modules\Settings\Models\PlatformSetting;

class PlatformSettingsRepository implements SettingsRepositoryInterface
{
    private const CACHE_TAG = 'platform_settings';

    private const DEFAULT_GROUP = 'general';

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = $this->find(self::DEFAULT_GROUP, $key);

        if (! $setting) {
            return $default;
        }

        $value = $setting->value;

        if ($setting->is_encrypted && is_array($value) && isset($value['encrypted'])) {
            try {
                $value = json_decode(Crypt::decryptString($value['encrypted']), true);
            } catch (\Throwable) {
                return $default;
            }
        } elseif (is_array($value) && array_key_exists('raw', $value)) {
            $value = $value['raw'];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $group = self::DEFAULT_GROUP;
        $setting = $this->find($group, $key);

        $encrypted = false;
        $storedValue = $value;
        if (config('settings.encrypt_sensitive_keys', false) && $this->isSensitiveKey($key)) {
            $storedValue = ['encrypted' => Crypt::encryptString(json_encode($value))];
            $encrypted = true;
        } elseif (! is_array($value) && ! is_object($value)) {
            $storedValue = ['raw' => $value];
        }

        $payload = [
            'group' => $group,
            'key' => $key,
            'value' => $storedValue,
            'is_encrypted' => $encrypted,
        ];

        if ($setting) {
            $setting->update($payload);
        } else {
            PlatformSetting::create($payload);
        }

        $this->invalidateCache($group, $key);
    }

    public function forget(string $key): void
    {
        $this->find(self::DEFAULT_GROUP, $key)?->delete();
        $this->invalidateCache(self::DEFAULT_GROUP, $key);
    }

    public function has(string $key): bool
    {
        return $this->find(self::DEFAULT_GROUP, $key) !== null;
    }

    public function getGroup(string $group): array
    {
        $cacheKey = self::CACHE_TAG.':group:'.$group;

        return Cache::remember($cacheKey, 3600, function () use ($group) {
            return PlatformSetting::where('group', $group)->get()->mapWithKeys(function (PlatformSetting $s) {
                $value = $s->value;
                if ($s->is_encrypted && is_array($value) && isset($value['encrypted'])) {
                    try {
                        $value = json_decode(Crypt::decryptString($value['encrypted']), true);
                    } catch (\Throwable) {
                        $value = null;
                    }
                } elseif (is_array($value) && array_key_exists('raw', $value)) {
                    $value = $value['raw'];
                }

                return [$s->key => $value];
            })->all();
        });
    }

    private function find(string $group, string $key): ?PlatformSetting
    {
        $cacheKey = self::CACHE_TAG.':'.$group.':'.$key;

        return Cache::remember($cacheKey, 3600, fn () => PlatformSetting::where('group', $group)->where('key', $key)->first());
    }

    private function invalidateCache(string $group, ?string $key = null): void
    {
        Cache::forget(self::CACHE_TAG.':group:'.$group);
        if ($key !== null) {
            Cache::forget(self::CACHE_TAG.':'.$group.':'.$key);
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $sensitive = ['password', 'secret', 'api_key', 'token'];

        foreach ($sensitive as $s) {
            if (str_contains(strtolower($key), $s)) {
                return true;
            }
        }

        return false;
    }
}
