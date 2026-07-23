<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;

/**
 * Sealed filesystem store + AEAD (sodium_crypto_secretbox) for local_sealed.
 *
 * Envelope (JSON, no plaintext secret):
 * {
 *   "v": 1,
 *   "alg": "sodium_secretbox",
 *   "nonce": "<base64>",
 *   "ciphertext": "<base64>",
 *   "reference_hash": "<sha256 hex>",
 *   "status": "active|revoked",
 *   "created_at": "<iso8601>",
 *   "rotated_at": "<iso8601|null>"
 * }
 */
final class LocalSealedEngine
{
    public const PROVIDER_KEY = 'local_sealed';

    public const ALG = 'sodium_secretbox';

    public function __construct(
        private readonly LocalSealedPathResolver $paths,
        private readonly LocalSealedKeySource $keys,
        private readonly string $runtimeClass,
        /** @var list<string> */
        private readonly array $allowedRuntimeClasses,
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
            $envelope = $this->readEnvelope($reference);
            $this->assertReferenceBinding($reference, $envelope);

            return [
                'status' => (string) ($envelope['status'] ?? 'unknown'),
                'last_verified_at' => isset($envelope['rotated_at'])
                    ? (string) $envelope['rotated_at']
                    : (isset($envelope['created_at']) ? (string) $envelope['created_at'] : null),
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
                $envelope = $this->readEnvelope($reference);
                $this->assertReferenceBinding($reference, $envelope);

                if (($envelope['status'] ?? null) !== 'active') {
                    return SecretResolutionResult::failure('credential_revoked_or_inactive');
                }

                $plaintext = $this->decryptEnvelope($envelope);

                return SecretResolutionResult::success($plaintext);
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
            // Preserve prior valid credential until new sealed file is committed.
            $prior = null;
            if (is_file($path)) {
                $prior = $this->readEnvelopeAt($path);
                $this->assertReferenceBinding($reference, $prior);
                if (($prior['status'] ?? null) === 'revoked') {
                    throw LocalSealedException::failClosed('cannot_rotate_revoked');
                }
                // Prove prior still decrypts with current key before replacing.
                $this->decryptEnvelope($prior);
            }

            $createdAt = is_array($prior) ? (string) ($prior['created_at'] ?? now()->toIso8601String()) : now()->toIso8601String();
            $envelope = $this->sealEnvelope($reference, $plaintext, 'active', $createdAt, now()->toIso8601String());

            try {
                $this->atomicWrite($path, $envelope);
            } catch (\Throwable) {
                // Prior file untouched if rename never happened.
                throw LocalSealedException::failClosed('rotation_failed');
            }

            // Verify new payload decrypts; if not, attempt restore of prior bytes.
            try {
                $written = $this->readEnvelopeAt($path);
                $this->decryptEnvelope($written);
            } catch (\Throwable) {
                if (is_array($prior)) {
                    $this->atomicWrite($path, $prior);
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

            $prior = $this->readEnvelopeAt($path);
            $this->assertReferenceBinding($reference, $prior);

            // Keep AEAD blob but mark revoked — resolve must fail; no plaintext rewrite.
            $prior['status'] = 'revoked';
            $prior['rotated_at'] = now()->toIso8601String();
            $this->atomicWrite($path, $prior);
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
     * @return array<string, mixed>
     */
    private function readEnvelope(SecretReference $reference): array
    {
        $path = $this->paths->resolveSealPath($reference);
        if (! is_file($path)) {
            throw LocalSealedException::failClosed('missing_sealed_payload');
        }

        return $this->readEnvelopeAt($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function readEnvelopeAt(string $path): array
    {
        $this->paths->assertNoSymlinkEscape($path);
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        if (! is_array($decoded) || ($decoded['alg'] ?? null) !== self::ALG || (int) ($decoded['v'] ?? 0) !== 1) {
            throw LocalSealedException::failClosed('corrupted_sealed_payload');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertReferenceBinding(SecretReference $reference, array $envelope): void
    {
        $expected = hash('sha256', $reference->provider.'|'.$reference->reference);
        $actual = (string) ($envelope['reference_hash'] ?? '');
        if ($actual === '' || ! hash_equals($expected, $actual)) {
            throw LocalSealedException::failClosed('reference_binding_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return non-empty-string
     */
    private function decryptEnvelope(array $envelope): string
    {
        $key = $this->keys->loadKey();
        $nonce = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);

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

        return $plain;
    }

    /**
     * @return array<string, mixed>
     */
    private function sealEnvelope(
        SecretReference $reference,
        string $plaintext,
        string $status,
        string $createdAt,
        ?string $rotatedAt,
    ): array {
        $key = $this->keys->loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return [
            'v' => 1,
            'alg' => self::ALG,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($cipher),
            'reference_hash' => hash('sha256', $reference->provider.'|'.$reference->reference),
            'status' => $status,
            'created_at' => $createdAt,
            'rotated_at' => $rotatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function atomicWrite(string $path, array $envelope): void
    {
        $dir = dirname($path);
        $tmp = $dir.DIRECTORY_SEPARATOR.'.'.$this->safeBasename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);

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

        // Restrictive permissions where supported (no-op nuance on Windows ACLs).
        @chmod($tmp, 0600);

        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw LocalSealedException::failClosed('atomic_write_failed');
        }

        @chmod($path, 0600);
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
