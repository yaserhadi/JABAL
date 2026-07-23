<?php

namespace Modules\Identity\Support\Sso\Credentials;

use InvalidArgumentException;

final class SecretProviderRegistry
{
    /** @var array<string, SecretProviderRuntime> */
    private array $runtime = [];

    /** @var array<string, SecretProviderManagement> */
    private array $management = [];

    public function registerRuntime(SecretProviderRuntime $provider): void
    {
        $key = $provider->providerKey();
        if ($key === '') {
            throw new InvalidArgumentException('Provider key must be non-empty.');
        }
        $this->runtime[$key] = $provider;
    }

    public function registerManagement(SecretProviderManagement $provider): void
    {
        $key = $provider->providerKey();
        if ($key === '') {
            throw new InvalidArgumentException('Provider key must be non-empty.');
        }
        $this->management[$key] = $provider;
    }

    public function runtime(string $providerKey): SecretProviderRuntime
    {
        if (! isset($this->runtime[$providerKey])) {
            throw new InvalidArgumentException("Unregistered secret provider runtime: {$providerKey}");
        }

        return $this->runtime[$providerKey];
    }

    public function management(string $providerKey): SecretProviderManagement
    {
        if (! isset($this->management[$providerKey])) {
            throw new InvalidArgumentException("Unregistered secret provider management: {$providerKey}");
        }

        return $this->management[$providerKey];
    }

    public function hasRuntime(string $providerKey): bool
    {
        return isset($this->runtime[$providerKey]);
    }

    /** @return list<string> */
    public function registeredRuntimeKeys(): array
    {
        return array_keys($this->runtime);
    }
}
