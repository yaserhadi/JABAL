<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-008 application control — login ownership, no token persistence, no secret leakage paths. */
class SsoApplicationControlTest extends TestCase
{
    /**
     * @return list<string>
     */
    protected function ssoPhpFiles(): array
    {
        return File::allFiles(base_path('Modules/Identity/app'));
    }

    #[Test]
    public function federated_login_guard_calls_exist_only_in_sso_auth_controller(): void
    {
        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/SsoAuthController.php'));
        $this->assertStringContainsString('Auth::guard(\'web\')->login', $controller);

        foreach ($this->ssoPhpFiles() as $file) {
            $path = $file->getPathname();
            if (str_contains($path, 'SsoAuthController.php')) {
                continue;
            }

            $basename = $file->getFilename();
            if (! str_starts_with($basename, 'Sso') && ! str_contains($path, DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($path);
            $this->assertStringNotContainsString('Auth::guard(', $source, $path);
            $this->assertStringNotContainsString('Auth::login(', $source, $path);
        }
    }

    #[Test]
    public function sso_services_never_establish_web_sessions(): void
    {
        foreach (['SsoAuthService.php', 'SsoConfigService.php'] as $relative) {
            $source = file_get_contents(base_path('Modules/Identity/app/Services/'.$relative));
            $this->assertStringNotContainsString('Auth::login(', $source);
            $this->assertStringNotContainsString('Auth::guard(', $source);
            $this->assertStringNotContainsString('session()->regenerate', $source);
        }

        $resolver = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoIdentityResolver.php'));
        $this->assertStringNotContainsString('Auth::login(', $resolver);
        $this->assertStringNotContainsString('Auth::guard(', $resolver);
    }

    #[Test]
    public function sso_code_has_no_token_persistence(): void
    {
        $redactionAllowlist = [
            'SsoObservabilityRedactor.php',
        ];

        foreach ($this->ssoPhpFiles() as $file) {
            $basename = $file->getFilename();
            if (in_array($basename, $redactionAllowlist, true)) {
                continue;
            }
            if (! str_starts_with($basename, 'Sso') && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('refresh_token', $source, $file->getPathname());
            $this->assertDoesNotMatchRegularExpression(
                '/->\s*(create|update|insert)\s*\([^)]*access_token/i',
                $source,
                $file->getPathname()
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

        foreach ($this->ssoPhpFiles() as $file) {
            $basename = $file->getFilename();
            if (in_array($basename, $centralAllowlist, true)) {
                continue;
            }
            if (! str_starts_with($basename, 'Sso') && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString("connection('central')", $source, $file->getPathname());
            $this->assertStringNotContainsString('Central::', $source, $file->getPathname());
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
