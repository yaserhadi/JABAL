<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;

/**
 * Sealed filesystem store + AEAD (sodium_crypto_secretbox) for local_sealed.
 *
 * Outer envelope (untrusted until AEAD opens):
 * { "v": 2, "alg": "sodium_secretbox", "nonce": "<b64>", "ciphertext": "<b64>" }
 *
 * Inner authenticated plaintext JSON (status + reference_hash + secret):
 * { "secret": "...", "reference_hash": "...", "status": "active|revoked",
 *   "created_at": "...", "rotated_at": null }
 */
final class LocalSealedEngine
{
    public const PROVIDER_KEY = 'local_sealed';

    public const ALG = 'sodium_secretbox';

    public const ENVELOPE_VERSION = 2;

    public function __construct(
        private readonly LocalSealedPathResolver $paths,
        private readonly LocalSealedKeySource $keys,
        private readonly string $runtimeClass,
        /** @var list<string> */
        private readonly array $allowedRuntimeClasses,
        private readonly bool $productionStateActive = false,
        /** @var (callable(string, string): bool)|null */
        private $renameInterceptor = null,
    ) {}

    public function exists(SecretReference $reference): bool
    {
        $this->assertProvider($reference);
        $this->assertRuntimeAllowed();
        $path = $this->paths->resolveSealPath($reference);

        return is_file($path) && ! is_link($path);
    }

    /**
     * @return array{status?:string,last_verified_at?:string|null}
     */
    public function metadata(SecretReference $reference): array
    {
        $this->assertProvider($reference);
        $this->assertRuntimeAllowed();

        try {
            $inner = $this->openAuthenticated($reference);

            return [
                'status' => (string) ($inner['status'] ?? 'unknown'),
                'last_verified_at' => isset($inner['rotated_at'])
                    ? (string) $inner['rotated_at']
                    : (isset($inner['created_at']) ? (string) $inner['created_at'] : null),
            ];
        } catch (LocalSealedException) {
            return ['status' => 'missing'];
        }
    }

    public function resolve(SecretReference $reference): SecretResolutionResult
    {
        try {
            $this->assertProvider($reference);
            $this->assertRuntimeAllowed();
            $this->assertReferenceActive($reference);

            $path = $this->paths->resolveSealPath($reference);

            return $this->withLock($path, LOCK_SH, function () use ($reference): SecretResolutionResult {
                $inner = $this->openAuthenticated($reference);

                if (($inner['status'] ?? null) !== 'active') {
                    return SecretResolutionResult::failure('credential_revoked_or_inactive');
                }

                $secret = (string) ($inner['secret'] ?? '');
                if ($secret === '') {
                    return SecretResolutionResult::failure('aead_validation_failed');
                }

                return SecretResolutionResult::success($secret);
            });
        } catch (LocalSealedException $e) {
            return SecretResolutionResult::failure($this->reasonFromException($e));
        }
    }

    public function provision(SecretReference $reference, string $plaintext): void
    {
        $this->assertProvider($reference);
        $this->assertRuntimeAllowed();
        $this->assertPlaintext($plaintext);
        $this->assertReferenceActive($reference);

        $path = $this->paths->resolveSealPath($reference);
        $this->withLock($path, LOCK_EX, function () use ($reference, $plaintext, $path): void {
            if (is_file($path)) {
                throw LocalSealedException::failClosed('already_provisioned');
            }

            $envelope = $this->sealEnvelope($reference, $plaintext, 'active', now()->toIso8601String(), null);
            $this->atomicWrite($path, $envelope);
        });
    }

    public function rotate(SecretReference $reference, string $plaintext): void
    {
        $this->assertProvider($reference);
        $this->assertRuntimeAllowed();
        $this->assertPlaintext($plaintext);
        $this->assertReferenceActive($reference);

        $path = $this->paths->resolveSealPath($reference);
        $this->withLock($path, LOCK_EX, function () use ($reference, $plaintext, $path): void {
            $priorOuter = null;
            $priorInner = null;
            if (is_file($path)) {
                $priorOuter = $this->readOuterAt($path);
                $priorInner = $this->decryptInner($priorOuter);
                $this->assertInnerBinding($reference, $priorInner);
                if (($priorInner['status'] ?? null) === 'revoked') {
                    throw LocalSealedException::failClosed('cannot_rotate_revoked');
                }
            }

            $createdAt = is_array($priorInner)
                ? (string) ($priorInner['created_at'] ?? now()->toIso8601String())
                : now()->toIso8601String();
            $envelope = $this->sealEnvelope($reference, $plaintext, 'active', $createdAt, now()->toIso8601String());

            try {
                $this->atomicWrite($path, $envelope);
                $written = $this->readOuterAt($path);
                $this->decryptInner($written);
            } catch (\Throwable) {
                if (is_array($priorOuter)) {
                    $this->atomicWrite($path, $priorOuter);
                }
                throw LocalSealedException::failClosed('rotation_failed');
            }
        });
    }

    public function revoke(SecretReference $reference): void
    {
        $this->assertProvider($reference);
        $this->assertRuntimeAllowed();

        $path = $this->paths->resolveSealPath($reference);
        $this->withLock($path, LOCK_EX, function () use ($reference, $path): void {
            if (! is_file($path)) {
                throw LocalSealedException::failClosed('missing_sealed_payload');
            }

            $inner = $this->openAuthenticated($reference);
            $secret = (string) ($inner['secret'] ?? '');
            if ($secret === '') {
                throw LocalSealedException::failClosed('aead_validation_failed');
            }

            $envelope = $this->sealEnvelope(
                $reference,
                $secret,
                'revoked',
                (string) ($inner['created_at'] ?? now()->toIso8601String()),
                now()->toIso8601String(),
            );
            $this->atomicWrite($path, $envelope);
        });
    }

    private function assertProvider(SecretReference $reference): void
    {
        if ($reference->provider !== self::PROVIDER_KEY) {
            throw LocalSealedException::failClosed('provider_mismatch');
        }
    }

    private function assertRuntimeAllowed(): void
    {
        if ($this->productionStateActive) {
            throw LocalSealedException::failClosed('production_state_denied');
        }

        $class = strtolower(trim($this->runtimeClass));
        if ($class === '') {
            throw LocalSealedException::failClosed('missing_runtime_class');
        }
        if ($class === 'production') {
            throw LocalSealedException::failClosed('production_runtime_denied');
        }
        if (! in_array($class, array_map('strtolower', $this->allowedRuntimeClasses), true)) {
            throw LocalSealedException::failClosed('unknown_or_disallowed_runtime_class');
        }
    }

    private function assertReferenceActive(SecretReference $reference): void
    {
        if ($reference->status !== null && strtolower($reference->status) !== 'active') {
            throw LocalSealedException::failClosed('credential_not_active');
        }
    }

    private function assertPlaintext(string $plaintext): void
    {
        if ($plaintext === '') {
            throw LocalSealedException::failClosed('empty_plaintext');
        }
    }

    /**
     * @return array{secret:string,reference_hash:string,status:string,created_at?:string,rotated_at?:?string}
     */
    private function openAuthenticated(SecretReference $reference): array
    {
        $path = $this->paths->resolveSealPath($reference);
        if (! is_file($path)) {
            throw LocalSealedException::failClosed('missing_sealed_payload');
        }

        $outer = $this->readOuterAt($path);
        $inner = $this->decryptInner($outer);
        $this->assertInnerBinding($reference, $inner);

        return $inner;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOuterAt(string $path): array
    {
        $root = $this->paths->assertStoreConfigured();
        $this->paths->assertNoSymlinkEscape($path, $root);

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        if (! is_array($decoded)
            || ($decoded['alg'] ?? null) !== self::ALG
            || (int) ($decoded['v'] ?? 0) !== self::ENVELOPE_VERSION) {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $outer
     * @return array{secret:string,reference_hash:string,status:string,created_at?:string,rotated_at?:?string}
     */
    private function decryptInner(array $outer): array
    {
        $key = $this->keys->loadKey();
        $nonce = base64_decode((string) ($outer['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($outer['ciphertext'] ?? ''), true);

        if ($nonce === false || $cipher === false
            || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            sodium_memzero($key);
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);

        if ($plain === false || $plain === '') {
            throw LocalSealedException::failClosed('aead_validation_failed');
        }

        try {
            $inner = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw LocalSealedException::failClosed('aead_validation_failed');
        }

        if (! is_array($inner)
            || ! isset($inner['secret'], $inner['reference_hash'], $inner['status'])
            || ! is_string($inner['secret'])
            || ! is_string($inner['reference_hash'])
            || ! is_string($inner['status'])) {
            throw LocalSealedException::failClosed('aead_validation_failed');
        }

        return $inner;
    }

    /**
     * @param  array<string, mixed>  $inner
     */
    private function assertInnerBinding(SecretReference $reference, array $inner): void
    {
        $expected = hash('sha256', $reference->provider.'|'.$reference->reference);
        $actual = (string) ($inner['reference_hash'] ?? '');
        if ($actual === '' || ! hash_equals($expected, $actual)) {
            throw LocalSealedException::failClosed('reference_binding_mismatch');
        }
    }

    /**
     * @return array{v:int,alg:string,nonce:string,ciphertext:string}
     */
    private function sealEnvelope(
        SecretReference $reference,
        string $plaintext,
        string $status,
        string $createdAt,
        ?string $rotatedAt,
    ): array {
        $inner = [
            'secret' => $plaintext,
            'reference_hash' => hash('sha256', $reference->provider.'|'.$reference->reference),
            'status' => $status,
            'created_at' => $createdAt,
            'rotated_at' => $rotatedAt,
        ];

        $key = $this->keys->loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox(
            json_encode($inner, JSON_THROW_ON_ERROR),
            $nonce,
            $key,
        );
        sodium_memzero($key);

        return [
            'v' => self::ENVELOPE_VERSION,
            'alg' => self::ALG,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($cipher),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function atomicWrite(string $path, array $envelope): void
    {
        $root = $this->paths->assertStoreConfigured();
        $dir = dirname($path);
        $tmp = $dir.DIRECTORY_SEPARATOR.'.'.$this->safeBasename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);

        try {
            $this->paths->assertTempPathAllowed($tmp, $root);

            $fh = fopen($tmp, 'cb');
            if ($fh === false) {
                throw LocalSealedException::failClosed('atomic_write_failed');
            }

            try {
                if (fwrite($fh, $json) === false) {
                    throw LocalSealedException::failClosed('atomic_write_failed');
                }
                fflush($fh);
                if (function_exists('fsync')) {
                    @fsync($fh);
                }
            } finally {
                fclose($fh);
            }

            @chmod($tmp, 0600);

            $renamed = $this->renameInterceptor !== null
                ? (bool) ($this->renameInterceptor)($tmp, $path)
                : rename($tmp, $path);

            if (! $renamed) {
                throw LocalSealedException::failClosed('atomic_write_failed');
            }

            @chmod($path, 0600);
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            if ($e instanceof LocalSealedException) {
                throw $e;
            }
            throw LocalSealedException::failClosed('atomic_write_failed');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(string $sealPath, int $mode, callable $callback): mixed
    {
        $lockPath = $this->paths->lockPath($sealPath);
        $lockDir = dirname($lockPath);
        if (! is_dir($lockDir) && ! mkdir($lockDir, 0700, true) && ! is_dir($lockDir)) {
            throw LocalSealedException::failClosed('store_mkdir_failed');
        }

        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw LocalSealedException::failClosed('lock_failed');
        }

        try {
            if (! flock($fh, $mode)) {
                throw LocalSealedException::failClosed('lock_failed');
            }

            return $callback();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function safeBasename(string $path): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($path)) ?: 'seal';
    }

    private function reasonFromException(LocalSealedException $e): string
    {
        if (preg_match('/local_sealed failed closed: (.+)$/', $e->getMessage(), $m) === 1) {
            return $m[1];
        }

        return 'local_sealed_error';
    }
}
