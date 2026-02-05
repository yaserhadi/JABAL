<?php

namespace Modules\Settings\Services;

use App\Support\Contracts\Settings\SettingsRepositoryInterface;
use Modules\Settings\Events\SettingUpdated;

class PlatformSettingsService
{
    public function __construct(
        private readonly SettingsRepositoryInterface $repository
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $oldValue = $this->repository->get($key);
        $this->repository->set($key, $value);
        SettingUpdated::dispatch($key, $oldValue, $value, 'general');
    }

    public function forget(string $key): void
    {
        $oldValue = $this->repository->get($key);
        $this->repository->forget($key);
        SettingUpdated::dispatch($key, $oldValue, null, 'general');
    }

    public function has(string $key): bool
    {
        return $this->repository->has($key);
    }

    public function getGroup(string $group): array
    {
        return $this->repository->getGroup($group);
    }
}
