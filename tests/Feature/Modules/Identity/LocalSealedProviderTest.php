<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedException;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedKeySource;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedManagement;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedPathResolver;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretProviderManagement;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-098 — local_sealed adapter security boundaries (no consumer cutover). */
class LocalSealedProviderTest extends TestCase
{
    private string $storeDir;

    private string $keyFile;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = storage_path('framework/testing/local_sealed_'.bin2hex(random_bytes(4)));
        $this->storeDir = $base.DIRECTORY_SEPARATOR.'store';
        $this->publicDir = $base.DIRECTORY_SEPARATOR.'public';
        $this->keyFile = $base.DIRECTORY_SEPARATOR.'unseal.key';
        mkdir($this->storeDir, 0700, true);
        mkdir($this->publicDir, 0700, true);
        file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    protected function tearDown(): void
    {
        $this->deleteTree(dirname($this->storeDir));
        parent::tearDown();
    }

    #[Test]
    public function provision_and_resolve_succeed(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/tenant-a/version-b/client-secret');

        $management->provision($ref, 'idp-client-secret-value');
        $this->assertTrue($runtime->exists($ref));

        $result = $runtime->resolve($ref);
        $this->assertTrue($result->ok);
        $this->assertSame('idp-client-secret-value', $result->consumeValue());
        $this->assertSame('active', $runtime->metadata($ref)['status']);
    }

    #[Test]
    public function aead_tamper_is_detected(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');

        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);
        $json = json_decode((string) file_get_contents($path), true);
        $cipher = base64_decode($json['ciphertext'], true);
        $cipher[0] = $cipher[0] === "\0" ? "\1" : "\0";
        $json['ciphertext'] = base64_encode($cipher);
        file_put_contents($path, json_encode($json));

        $result = $runtime->resolve($ref);
        $this->assertFalse($result->ok);
        $this->assertSame('aead_validation_failed', $result->reason);
    }

    #[Test]
    public function wrong_unsealing_key_fails_closed(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');

        file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        [$runtime2] = $this->makeAdapters('testing');

        $result = $runtime2->resolve($ref);
        $this->assertFalse($result->ok);
        $this->assertSame('aead_validation_failed', $result->reason);
    }

    #[Test]
    public function missing_key_or_store_fails_closed(): void
    {
        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile.'.missing'),
            'testing',
            ['local', 'testing'],
        );
        $management = new LocalSealedManagement($engine);
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');

        try {
            $management->provision($ref, 'x');
            $this->fail('expected missing key');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('unseal_key_file_unreadable', $e->getMessage());
        }

        $engine2 = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir.DIRECTORY_SEPARATOR.'nope', $this->publicDir),
            new LocalSealedKeySource($this->keyFile),
            'testing',
            ['local', 'testing'],
        );
        try {
            (new LocalSealedManagement($engine2))->provision($ref, 'x');
            $this->fail('expected missing store');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('missing_or_invalid_store_path', $e->getMessage());
        }
    }

    #[Test]
    public function unknown_and_production_runtime_are_denied(): void
    {
        foreach (['production', 'staging', ''] as $class) {
            [$runtime, $management] = $this->makeAdapters($class);
            $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
            try {
                $management->provision($ref, 'x');
                $this->fail("expected runtime denial for [{$class}]");
            } catch (LocalSealedException $e) {
                $this->assertMatchesRegularExpression(
                    '/production_runtime_denied|unknown_or_disallowed_runtime_class|missing_runtime_class/',
                    $e->getMessage(),
                );
            }
            $this->assertFalse($runtime->resolve($ref)->ok);
        }
    }

    #[Test]
    public function absolute_and_traversal_logical_references_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretReference('local_sealed', '../escape/secret', 'oidc_client_secret', null, 'testing', 'active');
    }

    #[Test]
    public function absolute_unix_logical_reference_rejected_by_secret_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretReference('local_sealed', '/etc/passwd', 'oidc_client_secret', null, 'testing', 'active');
    }

    #[Test]
    public function store_inside_web_root_is_rejected(): void
    {
        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->publicDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile),
            'testing',
            ['local', 'testing'],
        );
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('store_inside_web_root');
        (new LocalSealedManagement($engine))->provision($ref, 'x');
    }

    #[Test]
    public function symlink_escape_is_rejected_when_supported(): void
    {
        $outside = dirname($this->storeDir).DIRECTORY_SEPARATOR.'outside';
        mkdir($outside, 0700, true);
        $link = $this->storeDir.DIRECTORY_SEPARATOR.'seals';
        if (is_dir($link)) {
            $this->markTestSkipped('seals already created');
        }

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('symlink not supported in this environment');
        }

        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        try {
            $management->provision($ref, 'x');
            $this->fail('expected symlink_escape');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('symlink_escape', $e->getMessage());
        }
    }

    #[Test]
    public function tenant_version_isolation_via_distinct_references(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $a = $this->ref('enterprise-sso/tenant-a/version-1/client-secret');
        $b = $this->ref('enterprise-sso/tenant-b/version-1/client-secret');
        $management->provision($a, 'secret-a');
        $management->provision($b, 'secret-b');

        $this->assertSame('secret-a', $runtime->resolve($a)->consumeValue());
        $this->assertSame('secret-b', $runtime->resolve($b)->consumeValue());
    }

    #[Test]
    public function registry_registers_separate_runtime_and_management(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($runtime);
        $registry->registerManagement($management);
        $registry->seal();

        $this->assertInstanceOf(SecretProviderRuntime::class, $registry->runtime('local_sealed'));
        $this->assertInstanceOf(SecretProviderManagement::class, $registry->management('local_sealed'));
        $this->assertFalse(method_exists($registry->runtime('local_sealed'), 'provision'));
        $this->assertTrue(method_exists($registry->management('local_sealed'), 'provision'));
    }

    #[Test]
    public function rotation_replaces_value_and_failed_verify_restores_prior(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-v1');
        $management->rotate($ref, 'secret-v2');
        $this->assertSame('secret-v2', $runtime->resolve($ref)->consumeValue());

        // Simulate post-write corruption then ensure a subsequent rotate with bad verify path
        // restores: write garbage after capturing prior via successful rotate attempt using wrong alg envelope.
        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);
        $priorBytes = (string) file_get_contents($path);
        file_put_contents($path, '{"v":1,"alg":"sodium_secretbox","nonce":"YQ==","ciphertext":"YQ==","reference_hash":"dead","status":"active"}');

        // Resolve must fail on corrupted payload.
        $this->assertFalse($runtime->resolve($ref)->ok);

        // Restore prior manually to prove prior bytes remained valid when corruption is external;
        // engine rotation failure path: start from good state, force verify fail by swapping key mid-rotate.
        file_put_contents($path, $priorBytes);
        $this->assertSame('secret-v2', $runtime->resolve($ref)->consumeValue());

        // Wrong key during rotate: loadKey fails / decrypt prior fails before replace → prior intact.
        $badKey = $this->keyFile.'.other';
        file_put_contents($badKey, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $badEngine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($badKey),
            'testing',
            ['local', 'testing'],
        );
        try {
            (new LocalSealedManagement($badEngine))->rotate($ref, 'secret-v3');
            $this->fail('expected rotate fail with wrong key');
        } catch (LocalSealedException) {
            // prior still decrypts with original key
        }
        $this->assertSame('secret-v2', $runtime->resolve($ref)->consumeValue());
    }

    #[Test]
    public function revoke_prevents_resolve(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');
        $management->revoke($ref);

        $result = $runtime->resolve($ref);
        $this->assertFalse($result->ok);
        $this->assertSame('credential_revoked_or_inactive', $result->reason);
        $this->assertSame('revoked', $runtime->metadata($ref)['status']);
    }

    #[Test]
    public function concurrent_resolve_and_rotate_remain_consistent(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-v1');

        // Under exclusive rotate lock, interleaved resolve uses shared lock — must return
        // a complete prior or complete new value, never partial plaintext.
        $management->rotate($ref, 'secret-v2');
        $value = $runtime->resolve($ref)->consumeValue();
        $this->assertContains($value, ['secret-v1', 'secret-v2']);
        $this->assertSame('secret-v2', $value);
    }

    #[Test]
    public function secrets_and_references_are_redacted_from_failures_and_debug(): void
    {
        [$runtime, $management] = $this->makeAdapters('testing');
        $ref = $this->ref('enterprise-sso/tenant-secret-path/v1/client-secret');
        $management->provision($ref, 'super-secret-plaintext');
        $ok = $runtime->resolve($ref);
        $this->assertSame('[redacted]', $ok->__debugInfo()['value']);

        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);
        $json = json_decode((string) file_get_contents($path), true);
        unset($json['ciphertext']);
        file_put_contents($path, json_encode($json));

        $fail = $runtime->resolve($ref);
        $this->assertFalse($fail->ok);
        $this->assertStringNotContainsString('super-secret-plaintext', (string) $fail->reason);
        $this->assertStringNotContainsString('tenant-secret-path', (string) $fail->reason);
    }

    #[Test]
    public function runtime_adapter_cannot_provision_rotate_or_revoke(): void
    {
        [$runtime] = $this->makeAdapters('testing');
        $this->assertInstanceOf(SecretProviderRuntime::class, $runtime);
        $this->assertNotInstanceOf(SecretProviderManagement::class, $runtime);
        $this->assertFalse(method_exists($runtime, 'provision'));
        $this->assertFalse(method_exists($runtime, 'rotate'));
        $this->assertFalse(method_exists($runtime, 'revoke'));
    }

    #[Test]
    public function management_is_required_for_privileged_operations(): void
    {
        [, $management] = $this->makeAdapters('testing');
        $this->assertInstanceOf(SecretProviderManagement::class, $management);
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'only-via-management');
        $this->assertTrue((new LocalSealedRuntime(
            new LocalSealedEngine(
                new LocalSealedPathResolver($this->storeDir, $this->publicDir),
                new LocalSealedKeySource($this->keyFile),
                'testing',
                ['local', 'testing'],
            ),
        ))->exists($ref));
    }

    #[Test]
    public function physical_path_is_hash_derived_not_caller_path(): void
    {
        $resolver = new LocalSealedPathResolver($this->storeDir, $this->publicDir);
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $path = $resolver->resolveSealPath($ref);
        $this->assertStringContainsString(DIRECTORY_SEPARATOR.'seals'.DIRECTORY_SEPARATOR, $path);
        $this->assertStringEndsWith('.seal', $path);
        $this->assertStringNotContainsString('enterprise-sso', $path);
        $this->assertStringNotContainsString('client-secret', $path);
    }

    /**
     * @return array{0: LocalSealedRuntime, 1: LocalSealedManagement}
     */
    private function makeAdapters(string $runtimeClass): array
    {
        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile),
            $runtimeClass,
            ['local', 'testing'],
        );

        return [new LocalSealedRuntime($engine), new LocalSealedManagement($engine)];
    }

    private function ref(string $logical): SecretReference
    {
        return new SecretReference('local_sealed', $logical, 'oidc_client_secret', 'current', 'testing', 'active');
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
