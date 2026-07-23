<?php

namespace Tests\Feature\Modules\Identity;

use InvalidArgumentException;
use LogicException;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\Credentials\CredentialPurpose;
use Modules\Identity\Support\Sso\Credentials\CredentialResolutionException;
use Modules\Identity\Support\Sso\Credentials\IdpCredentialResolver;
use Modules\Identity\Support\Sso\Credentials\SecretProviderManagement;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-098 — Neutral credential foundation + fail-closed corrections. */
class IdpCredentialReferenceFoundationTest extends TestCase
{
    use GrantsSsoEntitlement;

    public int $resolveCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'identity.secrets.runtime_class' => 'testing',
            'identity.secrets.known_runtime_classes' => ['local', 'testing', 'staging', 'production'],
            'identity.secrets.environment_scopes_by_runtime_class' => [
                'local' => ['local'],
                'testing' => ['testing', 'local'],
                'staging' => ['staging'],
                'production' => ['production'],
            ],
        ]);
        $this->resolveCalls = 0;
    }

    #[Test]
    public function new_versions_default_to_legacy_encrypted_credential_source(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertSame(TenantSsoConfigVersion::CREDENTIAL_SOURCE_LEGACY_ENCRYPTED, $version->credential_source);

            $parent = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $this->assertFalse(array_key_exists('credential_provider', $parent->getAttributes()));
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function reference_material_fields_are_immutable_after_draft(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->expectException(LogicException::class);
            $version->update([
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => 'local_sealed',
                'credential_reference' => 'enterprise-sso/t/v/client-secret',
                'credential_type' => 'oidc_client_secret',
            ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function duplicate_runtime_registration_fails_closed(): void
    {
        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($this->stubRuntime('local_sealed'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate secret provider runtime registration');
        $registry->registerRuntime($this->stubRuntime('local_sealed'));
    }

    #[Test]
    public function duplicate_management_registration_fails_closed(): void
    {
        $registry = new SecretProviderRegistry;
        $registry->registerManagement($this->stubManagement('local_sealed'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate secret provider management registration');
        $registry->registerManagement($this->stubManagement('local_sealed'));
    }

    #[Test]
    public function case_and_whitespace_provider_key_collisions_fail_closed(): void
    {
        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($this->stubRuntime('local_sealed'));

        foreach (['LOCAL_SEALED', ' Local_Sealed ', 'local_sealed'] as $collision) {
            try {
                $registry->registerRuntime($this->stubRuntime($collision));
                $this->fail("Expected duplicate rejection for key [{$collision}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Duplicate secret provider runtime registration', $e->getMessage());
            }
        }
    }

    #[Test]
    public function sealed_registry_rejects_further_registration(): void
    {
        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($this->stubRuntime('local_sealed'));
        $registry->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sealed');
        $registry->registerRuntime($this->stubRuntime('other_provider'));
    }

    #[Test]
    public function only_active_status_may_resolve(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion(['credential_status' => 'active']);
        $this->withStubProvider();

        $result = app(IdpCredentialResolver::class)->resolveForVersion(
            $tenant,
            $version,
            CredentialPurpose::OidcClientAuth,
        );
        $this->assertTrue($result->ok);
        $this->assertSame(1, $this->resolveCalls);
    }

    #[Test]
    #[DataProvider('nonActiveStatuses')]
    public function non_active_statuses_fail_closed_before_provider(?string $status): void
    {
        [$tenant, $version] = $this->makeReferenceVersion(['credential_status' => $status]);
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
            $this->fail('Expected credential_not_active');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('credential_not_active', $e->getMessage());
            $this->assertSame(0, $this->resolveCalls);
        }
    }

    /** @return array<string, array{0: ?string}> */
    public static function nonActiveStatuses(): array
    {
        return [
            'null' => [null],
            'pending' => ['pending'],
            'disabled' => ['disabled'],
            'unknown' => ['unknown'],
            'revoked' => ['revoked'],
            'corrupted' => ['corrupted'],
        ];
    }

    #[Test]
    public function missing_environment_scope_fails_before_provider(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion(['credential_environment_scope' => null]);
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
            $this->fail('Expected missing_environment_scope');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('missing_environment_scope', $e->getMessage());
            $this->assertSame(0, $this->resolveCalls);
        }
    }

    #[Test]
    public function missing_or_unknown_runtime_class_fails_before_provider(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion();
        $this->withStubProvider();
        $resolver = app(IdpCredentialResolver::class);

        config(['identity.secrets.runtime_class' => null]);
        try {
            $resolver->resolveForVersion($tenant, $version, CredentialPurpose::OidcClientAuth);
            $this->fail('Expected missing_runtime_class');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('missing_runtime_class', $e->getMessage());
        }

        config(['identity.secrets.runtime_class' => 'not_a_known_class']);
        try {
            $resolver->resolveForVersion($tenant, $version, CredentialPurpose::OidcClientAuth);
            $this->fail('Expected unknown_runtime_class');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('unknown_runtime_class', $e->getMessage());
        }

        $this->assertSame(0, $this->resolveCalls);
    }

    #[Test]
    public function environment_mismatch_fails_before_provider(): void
    {
        config(['identity.secrets.runtime_class' => 'production']);
        [$tenant, $version] = $this->makeReferenceVersion([
            'credential_environment_scope' => 'local',
        ]);
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
            $this->fail('Expected environment_mismatch');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('environment_mismatch', $e->getMessage());
            $this->assertSame(0, $this->resolveCalls);
        }
    }

    #[Test]
    public function credential_type_mismatch_fails_before_provider(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion([
            'credential_type' => 'private_key_jwt',
        ]);
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
            $this->fail('Expected credential_type_mismatch');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('credential_type_mismatch', $e->getMessage());
            $this->assertSame(0, $this->resolveCalls);
        }
    }

    #[Test]
    public function jwks_backchannel_purpose_does_not_resolve_symmetric_credential(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion();
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::BackchannelLogoutJwks,
            );
            $this->fail('Expected jwks_path_no_symmetric_credential');
        } catch (CredentialResolutionException $e) {
            $this->assertStringContainsString('jwks_path_no_symmetric_credential', $e->getMessage());
            $this->assertSame(0, $this->resolveCalls);
            $this->assertStringNotContainsString('enterprise-sso/', $e->getMessage());
            $this->assertStringNotContainsString('resolved-from-stub', $e->getMessage());
        }
    }

    #[Test]
    public function hs256_backchannel_purpose_may_resolve_matching_client_secret_type(): void
    {
        [$tenant, $version] = $this->makeReferenceVersion();
        $this->withStubProvider();

        $result = app(IdpCredentialResolver::class)->resolveForVersion(
            $tenant,
            $version,
            CredentialPurpose::BackchannelLogoutHs256,
        );
        $this->assertTrue($result->ok);
        $this->assertSame(1, $this->resolveCalls);
    }

    #[Test]
    public function legacy_source_fails_without_ciphertext_fallback_or_provider_call(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);
        $this->withStubProvider();

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);
            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));

            try {
                app(IdpCredentialResolver::class)->resolveForVersion(
                    $tenant,
                    $version,
                    CredentialPurpose::OidcClientAuth,
                );
                $this->fail('Expected legacy fail-closed');
            } catch (CredentialResolutionException $e) {
                $this->assertStringContainsString('legacy_encrypted_source_not_resolved_here', $e->getMessage());
                $this->assertSame(0, $this->resolveCalls);
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function failure_messages_and_debug_do_not_leak_secrets(): void
    {
        $ok = SecretResolutionResult::success('super-secret-value');
        $this->assertSame('[redacted]', $ok->__debugInfo()['value']);

        [$tenant, $version] = $this->makeReferenceVersion([
            'credential_reference' => 'enterprise-sso/tenant/version/client-secret',
            'credential_status' => 'pending',
        ]);
        $this->withStubProvider();

        try {
            app(IdpCredentialResolver::class)->resolveForVersion(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
            $this->fail('expected failure');
        } catch (CredentialResolutionException $e) {
            $this->assertStringNotContainsString('super-secret', $e->getMessage());
            $this->assertStringNotContainsString('enterprise-sso/tenant/version/client-secret', $e->getMessage());
        }
    }

    #[Test]
    public function registry_keeps_runtime_and_management_separate(): void
    {
        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($this->stubRuntime('stub'));
        $registry->registerManagement($this->stubManagement('stub'));

        $this->assertSame(['stub'], $registry->registeredRuntimeKeys());
        $this->assertNotSame($registry->runtime('stub'), $registry->management('stub'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Tenant, 1: TenantSsoConfigVersion}
     */
    private function makeReferenceVersion(array $overrides = []): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        $service->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-v1',
            'client_secret' => 'secret-v1',
        ]);

        $configId = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id');
        $attrs = array_merge([
            'tenant_id' => $tenant->id,
            'config_id' => $configId,
            'version_number' => 90 + random_int(1, 8),
            'status' => TenantSsoConfigVersion::STATUS_DRAFT,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-v1',
            'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
            'credential_provider' => 'local_sealed',
            'credential_reference' => 'enterprise-sso/'.$tenant->id.'/v/client-secret',
            'credential_type' => 'oidc_client_secret',
            'credential_environment_scope' => 'testing',
            'credential_status' => 'active',
        ], $overrides);

        $version = TenantSsoConfigVersion::query()->create($attrs);

        return [$tenant, $version];
    }

    private function withStubProvider(): void
    {
        $registry = app(SecretProviderRegistry::class);
        if ($registry->isSealed()) {
            $registry->unsealForTesting();
        }

        if (! $registry->hasRuntime('local_sealed')) {
            $registry->registerRuntime($this->countingRuntime('local_sealed'));
        }

        $registry->seal();
    }

    private function stubRuntime(string $key): SecretProviderRuntime
    {
        return new class($key) implements SecretProviderRuntime
        {
            public function __construct(private string $key) {}

            public function providerKey(): string
            {
                return $this->key;
            }

            public function exists(SecretReference $reference): bool
            {
                return false;
            }

            public function metadata(SecretReference $reference): array
            {
                return [];
            }

            public function resolve(SecretReference $reference): SecretResolutionResult
            {
                return SecretResolutionResult::failure('not_implemented');
            }
        };
    }

    private function countingRuntime(string $key): SecretProviderRuntime
    {
        $test = $this;

        return new class($key, $test) implements SecretProviderRuntime
        {
            public function __construct(private string $key, private IdpCredentialReferenceFoundationTest $test) {}

            public function providerKey(): string
            {
                return $this->key;
            }

            public function exists(SecretReference $reference): bool
            {
                return true;
            }

            public function metadata(SecretReference $reference): array
            {
                return ['status' => 'active'];
            }

            public function resolve(SecretReference $reference): SecretResolutionResult
            {
                $this->test->resolveCalls++;

                return SecretResolutionResult::success('resolved-from-stub');
            }
        };
    }

    private function stubManagement(string $key): SecretProviderManagement
    {
        return new class($key) implements SecretProviderManagement
        {
            public function __construct(private string $key) {}

            public function providerKey(): string
            {
                return $this->key;
            }

            public function provision(SecretReference $reference, string $plaintext): void {}

            public function rotate(SecretReference $reference, string $plaintext): void {}

            public function revoke(SecretReference $reference): void {}
        };
    }
}
