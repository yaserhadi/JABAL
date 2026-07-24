<?php

namespace Modules\Identity\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class IdentityServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Identity';

    protected string $nameLower = 'identity';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
        $this->registerLocalSealedBindings();
        $this->registerLocalSealedInRegistry();

        // BK-098: seal after boot so only boot-time providers may bind.
        $this->app->booted(function (): void {
            $this->app->make(\Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry::class)->seal();
        });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->singleton(\PragmaRX\Google2FA\Google2FA::class, fn () => new \PragmaRX\Google2FA\Google2FA);

        // BK-098: registry + resolver; local_sealed adapters bound in boot after config merge.
        $this->app->singleton(\Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry::class);
        $this->app->singleton(
            \Modules\Identity\Support\Sso\Credentials\IdpCredentialResolver::class,
            fn ($app) => new \Modules\Identity\Support\Sso\Credentials\IdpCredentialResolver(
                $app->make(\Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry::class),
            ),
        );
        $this->app->singleton(\Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService::class);
    }

    /**
     * Bind local_sealed engine + least-privilege Runtime/Management adapters.
     */
    protected function registerLocalSealedBindings(): void
    {
        $this->app->singleton(
            \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine::class,
            function () {
                $cfg = config('identity.secrets.local_sealed', []);
                $storePath = (string) ($cfg['store_path'] ?? '');
                if ($storePath !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $storePath)) {
                    $storePath = base_path($storePath);
                }
                $keyFile = isset($cfg['unseal_key_file']) ? (string) $cfg['unseal_key_file'] : null;
                if (is_string($keyFile) && $keyFile !== '' && ! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $keyFile)) {
                    $keyFile = base_path($keyFile);
                }

                return new \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine(
                    new \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedPathResolver(
                        $storePath,
                        public_path(),
                    ),
                    new \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedKeySource(
                        $keyFile,
                        $storePath,
                        public_path(),
                    ),
                    (string) config('identity.secrets.runtime_class', ''),
                    array_values(config('identity.secrets.allowed_runtime_classes_for_local_sealed', [
                        'local', 'development', 'test', 'controlled_uat',
                    ])),
                    (bool) config('identity.secrets.production_state_active', false),
                );
            },
        );

        $this->app->singleton(
            \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedRuntime::class,
            fn ($app) => new \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedRuntime(
                $app->make(\Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine::class),
            ),
        );

        $this->app->singleton(
            \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedManagement::class,
            fn ($app) => new \Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedManagement(
                $app->make(\Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine::class),
            ),
        );
    }

    /**
     * Register local_sealed only when explicitly enabled and runtime-class allowlisted.
     * Production / unknown / missing runtime class → do not register (fail closed).
     */
    protected function registerLocalSealedInRegistry(): void
    {
        if (! config('identity.secrets.local_sealed.enabled')) {
            return;
        }

        $runtimeClass = strtolower(trim((string) config('identity.secrets.runtime_class', '')));
        $allowed = array_map(
            'strtolower',
            array_values(config('identity.secrets.allowed_runtime_classes_for_local_sealed', [])),
        );

        if ($runtimeClass === '' || $runtimeClass === 'production' || ! in_array($runtimeClass, $allowed, true)) {
            return;
        }

        $registry = $this->app->make(\Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry::class);
        if ($registry->isSealed() || $registry->hasRuntime('local_sealed')) {
            return;
        }

        $registry->registerRuntime(
            $this->app->make(\Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedRuntime::class),
        );
        $registry->registerManagement(
            $this->app->make(\Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedManagement::class),
        );
    }

    /**
     * Register commands in the format of Command::class.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            \Modules\Identity\Console\CleanupSsoTransientDataCommand::class,
            \Modules\Identity\Console\PurgeLegacySsoDemoCredentialsCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('identity:sso-cleanup-transient')->everyFiveMinutes()->withoutOverlapping();
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $this->nameLower.'.'.$config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->merge_config_from($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function merge_config_from(string $path, string $key): void
    {
        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
