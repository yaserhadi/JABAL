<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

/**
 * Loads the unsealing key from an external file (never from DB / sealed payload / reports).
 */
final class LocalSealedKeySource
{
    public function __construct(
        private readonly ?string $keyFilePath,
    ) {}

    /**
     * @return non-empty-string Binary key material (SODIUM_CRYPTO_SECRETBOX_KEYBYTES).
     */
    public function loadKey(): string
    {
        if ($this->keyFilePath === null || trim($this->keyFilePath) === '') {
            throw LocalSealedException::failClosed('missing_unseal_key_file');
        }

        $path = $this->keyFilePath;
        if (str_contains($path, "\0")) {
            throw LocalSealedException::failClosed('invalid_unseal_key_file');
        }

        // Prohibit storing key under public web root or inside the sealed store (checked by caller config).
        if (! is_file($path) || ! is_readable($path)) {
            throw LocalSealedException::failClosed('unseal_key_file_unreadable');
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw LocalSealedException::failClosed('unseal_key_empty');
        }

        $raw = trim($raw);

        // Accept raw 32 bytes or base64-encoded 32 bytes.
        if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $raw;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        throw LocalSealedException::failClosed('invalid_unseal_key');
    }
}
