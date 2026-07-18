<?php

declare(strict_types=1);

namespace Tests\Support;

trait InteractsWithTenantAddressingProfile
{
    /** @var array<string, string|null> */
    private array $addressingEnvBackup = [];

    /**
     * Force addressing env BEFORE the application is created (routes register at boot).
     *
     * @param  array<string, string>  $overrides
     */
    protected function forceAddressingEnv(string $profile, array $overrides = []): void
    {
        $values = array_merge([
            'TENANCY_ADDRESSING_PROFILE' => $profile,
            'TENANT_PLATFORM_BASE_DOMAIN' => 'jabal.test',
            'TENANCY_PLATFORM_HOST' => $profile === 'host' ? 'platform.jabal.test' : 'localhost',
            'TENANCY_AUTH_HOST' => $profile === 'host' ? 'auth.jabal.test' : 'localhost',
            'TENANCY_API_HOST' => $profile === 'host' ? 'api.jabal.test' : '',
            'TENANCY_CENTRAL_HOSTS' => 'localhost,127.0.0.1,jabal.test,platform.jabal.test,auth.jabal.test,api.jabal.test',
            'TENANCY_CANONICAL_SCHEME' => $profile === 'host' ? 'https' : 'http',
            'APP_URL' => $profile === 'host' ? 'https://platform.jabal.test' : 'http://localhost',
        ], $overrides);

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->addressingEnvBackup)) {
                $this->addressingEnvBackup[$key] = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: null;
            }
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function restoreAddressingEnv(): void
    {
        foreach ($this->addressingEnvBackup as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->addressingEnvBackup = [];
    }
}
