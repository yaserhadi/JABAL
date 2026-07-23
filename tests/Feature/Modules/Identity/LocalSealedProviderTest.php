<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Support\Sso\Credentials\LocalSealed\FilesystemGuard;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BK-098 — local_sealed corrective security boundaries (no consumer cutover). */
class LocalSealedProviderTest extends TestCase
{
    private string $storeDir;

    private string $keyFile;

    private string $publicDir;

    private string $base;

    /** @var list<string> */
    private const ALLOWED = ['local', 'development', 'test', 'controlled_uat'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/local_sealed_'.bin2hex(random_bytes(4)));
        $this->storeDir = $this->base.DIRECTORY_SEPARATOR.'store';
        $this->publicDir = $this->base.DIRECTORY_SEPARATOR.'public';
        $this->keyFile = $this->base.DIRECTORY_SEPARATOR.'unseal.key';
        mkdir($this->storeDir, 0700, true);
        mkdir($this->publicDir, 0700, true);
        file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('allowedRuntimeClasses')]
    public function allowed_non_production_runtime_classes_may_operate(string $runtimeClass): void
    {
        [$runtime, $management] = $this->makeAdapters($runtimeClass);
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-ok');
        $this->assertSame('secret-ok', $runtime->resolve($ref)->consumeValue());
    }

    /** @return array<string, array{0: string}> */
    public static function allowedRuntimeClasses(): array
    {
        return [
            'local' => ['local'],
            'development' => ['development'],
            'test' => ['test'],
            'controlled_uat' => ['controlled_uat'],
        ];
    }

    #[Test]
    public function production_runtime_class_is_denied(): void
    {
        [, $management] = $this->makeAdapters('production');
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('production_runtime_denied');
        $management->provision($this->ref('enterprise-sso/t1/v1/client-secret'), 'x');
    }

    #[Test]
    public function missing_and_unknown_runtime_classes_are_denied(): void
    {
        foreach (['', 'staging', 'testing', 'uat'] as $class) {
            [, $management] = $this->makeAdapters($class);
            try {
                $management->provision($this->ref('enterprise-sso/t1/v1/client-secret'), 'x');
                $this->fail("expected denial for [{$class}]");
            } catch (LocalSealedException $e) {
                $this->assertMatchesRegularExpression(
                    '/missing_runtime_class|unknown_or_disallowed_runtime_class/',
                    $e->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function production_state_guard_denies_even_with_controlled_uat(): void
    {
        [, $management] = $this->makeAdapters('controlled_uat', productionState: true);
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('production_state_denied');
        $management->provision($this->ref('enterprise-sso/t1/v1/client-secret'), 'x');
    }

    #[Test]
    public function symlink_on_store_subdirectory_is_rejected(): void
    {
        $guard = $this->linkingGuard([$this->storeDir.DIRECTORY_SEPARATOR.'seals']);
        $engine = $this->engineWithGuard('test', $guard);
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('symlink_escape');
        (new LocalSealedManagement($engine))->provision($this->ref('enterprise-sso/t1/v1/client-secret'), 'x');
    }

    #[Test]
    public function symlink_on_seal_target_is_rejected(): void
    {
        [$runtime, $management] = $this->makeAdapters('test');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');
        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);

        $guard = $this->linkingGuard([$path]);
        $engine = $this->engineWithGuard('test', $guard);
        $result = (new LocalSealedRuntime($engine))->resolve($ref);
        $this->assertFalse($result->ok);
        $this->assertSame('symlink_escape', $result->reason);
    }

    #[Test]
    public function symlink_on_unseal_key_file_is_rejected(): void
    {
        $guard = $this->linkingGuard([$this->keyFile]);
        $keys = new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir, $guard);
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('unseal_key_symlink');
        $keys->loadKey();
    }

    #[Test]
    public function store_inside_web_root_is_rejected(): void
    {
        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->publicDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile, $this->publicDir, $this->publicDir),
            'test',
            self::ALLOWED,
        );
        $this->expectException(LocalSealedException::class);
        $this->expectExceptionMessage('store_inside_web_root');
        (new LocalSealedManagement($engine))->provision($this->ref('enterprise-sso/t1/v1/client-secret'), 'x');
    }

    #[Test]
    public function key_file_inside_web_root_or_store_is_rejected(): void
    {
        $inPublic = $this->publicDir.DIRECTORY_SEPARATOR.'bad.key';
        file_put_contents($inPublic, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        try {
            (new LocalSealedKeySource($inPublic, $this->storeDir, $this->publicDir))->loadKey();
            $this->fail('expected web-root key rejection');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('unseal_key_inside_web_root', $e->getMessage());
        }

        $inStore = $this->storeDir.DIRECTORY_SEPARATOR.'bad.key';
        file_put_contents($inStore, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        try {
            (new LocalSealedKeySource($inStore, $this->storeDir, $this->publicDir))->loadKey();
            $this->fail('expected store key rejection');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('unseal_key_inside_store', $e->getMessage());
        }
    }

    #[Test]
    public function wrong_key_length_and_encoding_are_rejected(): void
    {
        file_put_contents($this->keyFile, 'too-short');
        try {
            (new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir))->loadKey();
            $this->fail('expected invalid key');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('invalid_unseal_key', $e->getMessage());
        }

        file_put_contents($this->keyFile, base64_encode('not-32-bytes'));
        try {
            (new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir))->loadKey();
            $this->fail('expected invalid key encoding');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('invalid_unseal_key', $e->getMessage());
        }
    }

    #[Test]
    public function outer_metadata_tampering_cannot_change_authenticated_status_or_binding(): void
    {
        [$runtime, $management] = $this->makeAdapters('test');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');
        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);

        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        // Attacker adds unauthenticated outer fields — must be ignored; status lives inside AEAD.
        $json['status'] = 'active';
        $json['reference_hash'] = hash('sha256', 'forged');
        file_put_contents($path, json_encode($json));
        $this->assertSame('secret-a', $runtime->resolve($ref)->consumeValue());

        // Tamper ciphertext (authenticated blob) → fail closed.
        $cipher = base64_decode($json['ciphertext'], true);
        $cipher[0] = $cipher[0] === "\0" ? "\1" : "\0";
        $json['ciphertext'] = base64_encode($cipher);
        file_put_contents($path, json_encode($json));
        $fail = $runtime->resolve($ref);
        $this->assertFalse($fail->ok);
        $this->assertSame('aead_validation_failed', $fail->reason);
    }

    #[Test]
    public function temp_files_are_removed_after_failed_atomic_write(): void
    {
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $resolver = new LocalSealedPathResolver($this->storeDir, $this->publicDir);
        $path = $resolver->resolveSealPath($ref);
        $dir = dirname($path);

        $engine = new LocalSealedEngine(
            $resolver,
            new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir),
            'test',
            self::ALLOWED,
            false,
            static fn (string $tmp, string $dest): bool => false,
        );
        $management = new LocalSealedManagement($engine);

        try {
            $management->provision($ref, 'secret-a');
            $this->fail('expected atomic_write_failed');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('atomic_write_failed', $e->getMessage());
        }

        $tmpLeft = glob($dir.DIRECTORY_SEPARATOR.'.*.tmp') ?: [];
        $this->assertSame([], $tmpLeft);
        $this->assertFalse(is_file($path));
    }

    #[Test]
    public function failed_rotate_with_wrong_key_keeps_prior_resolvable(): void
    {
        [$runtime, $management] = $this->makeAdapters('test');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-v1');
        $management->rotate($ref, 'secret-v2');
        $this->assertSame('secret-v2', $runtime->resolve($ref)->consumeValue());

        $badKey = $this->base.DIRECTORY_SEPARATOR.'other.key';
        file_put_contents($badKey, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $bad = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($badKey, $this->storeDir, $this->publicDir),
            'test',
            self::ALLOWED,
        );
        try {
            (new LocalSealedManagement($bad))->rotate($ref, 'secret-v3');
            $this->fail('expected rotate fail');
        } catch (LocalSealedException) {
        }

        $this->assertSame('secret-v2', $runtime->resolve($ref)->consumeValue());
    }

    #[Test]
    public function provision_resolve_revoke_and_redaction(): void
    {
        [$runtime, $management] = $this->makeAdapters('controlled_uat');
        $ref = $this->ref('enterprise-sso/tenant-secret-path/v1/client-secret');
        $management->provision($ref, 'super-secret-plaintext');
        $ok = $runtime->resolve($ref);
        $this->assertTrue($ok->ok);
        $this->assertSame('[redacted]', $ok->__debugInfo()['value']);
        $this->assertSame('super-secret-plaintext', $ok->consumeValue());

        $management->revoke($ref);
        $fail = $runtime->resolve($ref);
        $this->assertFalse($fail->ok);
        $this->assertSame('credential_revoked_or_inactive', $fail->reason);
        $this->assertStringNotContainsString('super-secret-plaintext', (string) $fail->reason);
        $this->assertStringNotContainsString('tenant-secret-path', (string) $fail->reason);

        try {
            throw LocalSealedException::failClosed('demo');
        } catch (LocalSealedException $e) {
            $this->assertStringNotContainsString('super-secret', $e->getMessage());
            $this->assertStringNotContainsString($this->keyFile, $e->getMessage());
        }
    }

    #[Test]
    public function aead_tamper_wrong_key_missing_store_webroot_registry_runtime_boundary(): void
    {
        [$runtime, $management] = $this->makeAdapters('test');
        $ref = $this->ref('enterprise-sso/t1/v1/client-secret');
        $management->provision($ref, 'secret-a');

        $path = (new LocalSealedPathResolver($this->storeDir, $this->publicDir))->resolveSealPath($ref);
        $json = json_decode((string) file_get_contents($path), true);
        $cipher = base64_decode($json['ciphertext'], true);
        $cipher[0] = $cipher[0] === "\0" ? "\1" : "\0";
        $json['ciphertext'] = base64_encode($cipher);
        file_put_contents($path, json_encode($json));
        $this->assertSame('aead_validation_failed', $runtime->resolve($ref)->reason);

        file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        // Restore good seal with original key is gone — wrong key on fresh provision path:
        $this->assertFalse($this->makeAdapters('test')[0]->resolve($ref)->ok);

        $this->assertFalse(method_exists($runtime, 'provision'));
        $this->assertInstanceOf(SecretProviderRuntime::class, $runtime);
        $this->assertNotInstanceOf(SecretProviderManagement::class, $runtime);

        $registry = new SecretProviderRegistry;
        $registry->registerRuntime($runtime);
        $registry->registerManagement($management);
        $this->assertTrue($registry->hasRuntime('local_sealed'));

        $this->assertStringContainsString(DIRECTORY_SEPARATOR.'seals'.DIRECTORY_SEPARATOR, $path);
        $this->assertStringNotContainsString('enterprise-sso', $path);
    }

    #[Test]
    public function overly_broad_unix_permissions_are_rejected_when_detectable(): void
    {
        $guard = new class extends FilesystemGuard
        {
            public function hasOverlyBroadPermissions(string $path): bool
            {
                return true;
            }
        };

        try {
            (new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir, $guard))->loadKey();
            $this->fail('expected key permission denial');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('unseal_key_permissions_too_open', $e->getMessage());
        }

        try {
            (new LocalSealedPathResolver($this->storeDir, $this->publicDir, $guard))->assertStoreConfigured();
            $this->fail('expected store permission denial');
        } catch (LocalSealedException $e) {
            $this->assertStringContainsString('store_permissions_too_open', $e->getMessage());
        }
    }

    /**
     * @return array{0: LocalSealedRuntime, 1: LocalSealedManagement}
     */
    private function makeAdapters(string $runtimeClass, bool $productionState = false): array
    {
        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir),
            $runtimeClass,
            self::ALLOWED,
            $productionState,
        );

        return [new LocalSealedRuntime($engine), new LocalSealedManagement($engine)];
    }

    private function engineWithGuard(string $runtimeClass, FilesystemGuard $guard): LocalSealedEngine
    {
        return new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir, $guard),
            new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir, $guard),
            $runtimeClass,
            self::ALLOWED,
        );
    }

    /**
     * @param  list<string>  $linkedPaths
     */
    private function linkingGuard(array $linkedPaths): FilesystemGuard
    {
        $normalized = array_map(
            static fn (string $p): string => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p), DIRECTORY_SEPARATOR),
            $linkedPaths,
        );

        return new class($normalized) extends FilesystemGuard
        {
            /** @param  list<string>  $linked */
            public function __construct(private array $linked) {}

            public function isLink(string $path): bool
            {
                $needle = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

                return in_array($needle, $this->linked, true);
            }
        };
    }

    private function ref(string $logical): SecretReference
    {
        return new SecretReference('local_sealed', $logical, 'oidc_client_secret', 'current', 'test', 'active');
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
