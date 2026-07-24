<?php

namespace Modules\Identity\Support\Sso\Credentials;

use InvalidArgumentException;
use LogicException;

final class SecretProviderRegistry
{
    /** @var array<string, SecretProviderRuntime> */
    private array $runtime = [];

    /** @var array<string, SecretProviderManagement> */
    private array $management = [];

    private bool $sealed = false;

    public function registerRuntime(SecretProviderRuntime $provider): void
    {
        $this->assertNotSealed();
        $key = SecretProviderKey::canonicalize($provider->providerKey());

        if (isset($this->runtime[$key])) {
            throw new InvalidArgumentException("Duplicate secret provider runtime registration: {$key}");
        }

        $this->runtime[$key] = $provider;
    }

    public function registerManagement(SecretProviderManagement $provider): void
    {
        $this->assertNotSealed();
        $key = SecretProviderKey::canonicalize($provider->providerKey());

        if (isset($this->management[$key])) {
            throw new InvalidArgumentException("Duplicate secret provider management registration: {$key}");
        }

        $this->management[$key] = $provider;
    }

    public function runtime(string $providerKey): SecretProviderRuntime
    {
        $key = SecretProviderKey::canonicalize($providerKey);

        if (! isset($this->runtime[$key])) {
            throw new InvalidArgumentException("Unregistered secret provider runtime: {$key}");
        }

        return $this->runtime[$key];
    }

    public function management(string $providerKey): SecretProviderManagement
    {
        $key = SecretProviderKey::canonicalize($providerKey);

        if (! isset($this->management[$key])) {
            throw new InvalidArgumentException("Unregistered secret provider management: {$key}");
        }

        return $this->management[$key];
    }

    public function hasRuntime(string $providerKey): bool
    {
        try {
            $key = SecretProviderKey::canonicalize($providerKey);
        } catch (InvalidArgumentException) {
            return false;
        }

        return isset($this->runtime[$key]);
    }

    public function hasManagement(string $providerKey): bool
    {
        try {
            $key = SecretProviderKey::canonicalize($providerKey);
        } catch (InvalidArgumentException) {
            return false;
        }

        return isset($this->management[$key]);
    }

    /** @return list<string> */
    public function registeredRuntimeKeys(): array
    {
        return array_keys($this->runtime);
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * Testing only — allows test doubles to register after application boot seal.
     */
    public function unsealForTesting(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('SecretProviderRegistry::unsealForTesting is only allowed in testing.');
        }

        $this->sealed = false;
    }

    private function assertNotSealed(): void
    {
        if ($this->sealed) {
            throw new LogicException('SecretProviderRegistry is sealed; further registrations are denied.');
        }
    }
}
