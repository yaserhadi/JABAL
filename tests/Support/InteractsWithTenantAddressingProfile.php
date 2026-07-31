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

        // Keep Laravel config in sync when the app is already booted (mid-test profile switches).
        if (isset($this->app) && $this->app->bound('config')) {
            $this->app['config']->set('tenancy_addressing.profile', $profile);
            $this->app['config']->set('tenancy_addressing.platform_base_domain', $values['TENANT_PLATFORM_BASE_DOMAIN']);
            $this->app['config']->set('tenancy_addressing.platform_host', $values['TENANCY_PLATFORM_HOST']);
            $this->app['config']->set('tenancy_addressing.auth_host', $values['TENANCY_AUTH_HOST']);
            $this->app['config']->set('tenancy_addressing.api_host', $values['TENANCY_API_HOST']);
            $this->app['config']->set(
                'tenancy_addressing.central_hosts',
                array_values(array_filter(array_map(
                    static fn (string $h): string => strtolower(trim($h)),
                    explode(',', $values['TENANCY_CENTRAL_HOSTS'])
                )))
            );
            $this->app['config']->set('tenancy_addressing.canonical_scheme', $values['TENANCY_CANONICAL_SCHEME']);
            $this->app['config']->set('app.url', $values['APP_URL']);
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
