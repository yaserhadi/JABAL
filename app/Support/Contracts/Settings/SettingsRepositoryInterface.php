<?php

namespace App\Support\Contracts\Settings;

interface SettingsRepositoryInterface
{
    /**
     * Get a setting value by key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove a setting.
     */
    public function forget(string $key): void;

    /**
     * Check if a setting exists.
     */
    public function has(string $key): bool;

    /**
     * Get all settings in a group.
     */
    public function getGroup(string $group): array;
}
