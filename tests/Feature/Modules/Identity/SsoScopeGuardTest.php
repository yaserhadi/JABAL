<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-008 scope guards — deferred features and prohibited patterns must stay absent. */
class SsoScopeGuardTest extends TestCase
{
    #[Test]
    public function no_users_sso_enabled_column_in_tenant_migrations(): void
    {
        $migrationDir = base_path('database/migrations/tenant');
        foreach (File::allFiles($migrationDir) as $file) {
            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('sso_enabled', $source, $file->getPathname());
        }
    }

    #[Test]
    public function no_password_schema_changes_for_sso(): void
    {
        foreach (File::glob(base_path('database/migrations/tenant/*sso*')) as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('password', strtolower($source), $path);
        }
    }

    #[Test]
    public function identity_resolver_does_not_create_users_or_memberships(): void
    {
        $source = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoIdentityResolver.php'));

        $this->assertStringNotContainsString('TenantUser::query()->create', $source);
        $this->assertStringNotContainsString('User::create', $source);
        $this->assertStringNotContainsString('Membership::query()->create', $source);
        $this->assertStringNotContainsString('Membership::create', $source);
        $this->assertStringContainsString('TenantUserIdentity::query()->create', $source);
    }

    #[Test]
    public function no_saml_or_platform_sso_code_paths(): void
    {
        $identityModule = base_path('Modules/Identity');
        foreach (File::allFiles($identityModule) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\bSAML\b/i', $source, $file->getPathname());
            $this->assertStringNotContainsString('platform_sso', $source, $file->getPathname());
        }
    }

    #[Test]
    public function no_refresh_token_persistence_in_identity_module(): void
    {
        $identityModule = base_path('Modules/Identity');
        foreach (File::allFiles($identityModule) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $basename = $file->getFilename();
            if (! str_starts_with($basename, 'Sso') && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Sso'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('refresh_token', $source, $file->getPathname());
        }
    }

    #[Test]
    public function no_central_sso_tables(): void
    {
        $schema = DB::connection('central')->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('tenant_sso_config'));
        $this->assertFalse($schema->hasTable('tenant_user_identities'));
    }
}
