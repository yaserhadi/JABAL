<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-008 / BK-097 application control — dual Path+Host login authorities; no token persistence. */
class SsoApplicationControlTest extends TestCase
{
    /**
     * Federated login authorities (BK-097): Path OIDC callback + Host handoff only.
     *
     * @return list<string>
     */
    protected function federatedLoginAllowlist(): array
    {
        return [
            realpath(base_path('Modules/Identity/app/Http/Controllers/SsoAuthController.php')),
            realpath(base_path('Modules/Identity/app/Services/HostEnterpriseSsoHandoffService.php')),
        ];
    }

    /**
     * @return list<string>
     */
    protected function identityAppPhpFiles(): array
    {
        $paths = [];
        foreach (File::allFiles(base_path('Modules/Identity/app')) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    /**
     * All authorized login call forms that must only appear on allowlisted surfaces.
     */
    protected function loginCallPattern(): string
    {
        return '/(?:'
            .'\\bAuth::login\\s*\\('
            .'|\\bAuth::guard\\s*\\([^)]*\\)\\s*->\\s*login\\s*\\('
            .'|\\bauth\\s*\\(\\s*\\)\\s*->\\s*login\\s*\\('
            .'|\\bauth\\s*\\(\\s*[\'"][^\'"]+[\'"]\\s*\\)\\s*->\\s*login\\s*\\('
            .'|\\$[a-zA-Z_][a-zA-Z0-9_]*\\s*->\\s*login\\s*\\('
            .')/';
    }

    #[Test]
    public function federated_login_calls_exist_only_on_path_and_host_authorities(): void
    {
        $allowlist = $this->federatedLoginAllowlist();
        foreach ($allowlist as $path) {
            $this->assertNotFalse($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertMatchesRegularExpression($this->loginCallPattern(), $source, $path);
            $this->assertStringContainsString("Auth::guard('web')->login", $source, $path);
        }

        $pattern = $this->loginCallPattern();
        foreach ($this->identityAppPhpFiles() as $path) {
            $real = realpath($path);
            if ($real !== false && in_array($real, $allowlist, true)) {
                continue;
            }

            $source = file_get_contents($path);
            $this->assertIsString($source);

            // Password login (AuthController) is not a federated SSO authority.
            if (str_ends_with($path, 'AuthController.php') && ! str_ends_with($path, 'SsoAuthController.php')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression($pattern, $source, $path);
        }
    }

    #[Test]
    public function path_and_host_authorities_use_explicit_web_guard_only(): void
    {
        foreach ($this->federatedLoginAllowlist() as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('Auth::login(', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/Auth::guard\\(\\s*[\'"](?!web)[^\'"]+[\'"]\\s*\\)\\s*->\\s*login/', $source, $path);
        }
    }

    #[Test]
    public function sso_services_never_establish_web_sessions(): void
    {
        foreach (['SsoAuthService.php', 'SsoConfigService.php', 'HostEnterpriseSsoCallbackService.php'] as $relative) {
            $source = file_get_contents(base_path('Modules/Identity/app/Services/'.$relative));
            $this->assertDoesNotMatchRegularExpression($this->loginCallPattern(), $source, $relative);
            $this->assertStringNotContainsString('session()->regenerate()', $source, $relative);
        }

        $resolver = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoIdentityResolver.php'));
        $this->assertDoesNotMatchRegularExpression($this->loginCallPattern(), $resolver);
        $this->assertStringNotContainsString('Auth::guard(', $resolver);
    }

    #[Test]
    public function sso_code_has_no_token_persistence(): void
    {
        $redactionAllowlist = [
            'SsoObservabilityRedactor.php',
        ];

        foreach ($this->identityAppPhpFiles() as $path) {
            $basename = basename($path);
            if (in_array($basename, $redactionAllowlist, true)) {
                continue;
            }
            if (! str_starts_with($basename, 'Sso') && ! str_contains($path, DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($path);
            $this->assertStringNotContainsString('refresh_token', $source, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/->\s*(create|update|insert)\s*\([^)]*access_token/i',
                $source,
                $path
            );
        }
    }

    #[Test]
    public function sso_runtime_code_does_not_read_central_connection(): void
    {
        // BK-082 / DEC-0024: central is authoritative for Auth Txn, Handoff, BC Logout events,
        // and platform kill-switch controls — not for Tenant SSO config runtime data.
        $centralAllowlist = [
            'SsoBackChannelLogoutService.php',
            'SsoKillSwitchService.php',
            'SsoPlatformControl.php',
            'SsoBackchannelLogoutEvent.php',
            'SsoAuthenticationTransaction.php',
            'SsoTenantHandoff.php',
        ];

        foreach ($this->identityAppPhpFiles() as $path) {
            $basename = basename($path);
            if (in_array($basename, $centralAllowlist, true)) {
                continue;
            }
            if (! str_starts_with($basename, 'Sso') && ! str_contains($path, DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($path);
            $this->assertStringNotContainsString("connection('central')", $source, $path);
            $this->assertStringNotContainsString('Central::', $source, $path);
        }
    }

    #[Test]
    public function package_middleware_is_not_registered_for_sso_login(): void
    {
        $routes = file_get_contents(base_path('Modules/Identity/routes/web.php'));
        $this->assertStringNotContainsString('SessionCookieMiddleware', $routes);
        $this->assertStringNotContainsString('facile-it', $routes);

        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringNotContainsString('SessionCookieMiddleware', $bootstrap);
    }

    #[Test]
    public function nonce_is_prepared_for_oidc_authorization(): void
    {
        $adapter = file_get_contents(base_path('Modules/Identity/app/Support/Sso/LaravelSessionAuthSessionAdapter.php'));
        $service = file_get_contents(base_path('Modules/Identity/app/Services/SsoAuthService.php'));

        $this->assertStringContainsString('getNonce', $adapter);
        $this->assertStringContainsString("'nonce'", $service);
    }
}
