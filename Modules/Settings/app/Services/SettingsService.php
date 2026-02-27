<?php

namespace Modules\Settings\Services;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;

class SettingsService
{
    public function __construct(
        private SettingsRepositoryInterface $repository
    ) {
    }

    /**
     * Get a platform setting.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    /**
     * Set a platform setting.
     */
    public function set(string $key, mixed $value, array $options = []): void
    {
        $type = $options['type'] ?? $this->inferType($value);
        $group = $options['group'] ?? 'general';
        $isEncrypted = $options['encrypted'] ?? false;

        $this->repository->set($key, $value, $type, $group, $isEncrypted);
    }

    /**
     * Check if a setting exists.
     */
    public function has(string $key): bool
    {
        return $this->repository->has($key);
    }

    /**
     * Delete a setting.
     */
    public function forget(string $key): void
    {
        $this->repository->forget($key);
    }

    /**
     * Get all settings in a group.
     */
    public function getGroup(string $group): array
    {
        return $this->repository->getGroup($group);
    }

    /**
     * Flush settings cache.
     */
    public function flushCache(): void
    {
        $this->repository->flushCache();
    }

    /**
     * Infer the type from the value.
     */
    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
