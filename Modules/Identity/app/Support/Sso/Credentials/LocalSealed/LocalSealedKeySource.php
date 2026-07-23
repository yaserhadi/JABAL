<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

/**
 * Loads the unsealing key from an external file (never from DB / sealed payload / reports).
 */
final class LocalSealedKeySource
{
    public function __construct(
        private readonly ?string $keyFilePath,
        private readonly string $storeRoot,
        private readonly string $publicRoot,
        private readonly FilesystemGuard $fs = new FilesystemGuard,
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

        $this->assertKeyPlacement($path);
        $this->assertNoSymlinkComponents($path);

        if (! $this->fs->isFile($path) || ! $this->fs->isReadable($path)) {
            throw LocalSealedException::failClosed('unseal_key_file_unreadable');
        }

        if ($this->fs->hasOverlyBroadPermissions($path)) {
            throw LocalSealedException::failClosed('unseal_key_permissions_too_open');
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw LocalSealedException::failClosed('unseal_key_empty');
        }

        $raw = trim($raw);

        if (strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $raw;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        throw LocalSealedException::failClosed('invalid_unseal_key');
    }

    private function assertKeyPlacement(string $path): void
    {
        $realKey = $this->fs->realpath($path);
        $public = $this->fs->realpath($this->publicRoot);
        $store = trim($this->storeRoot) !== '' ? $this->fs->realpath($this->storeRoot) : false;

        if ($realKey === false) {
            // File may not exist yet for realpath; still reject obvious prefixes when parents resolve.
            $parent = dirname($path);
            $parentReal = $this->fs->realpath($parent);
            if ($parentReal !== false && $public !== false && $this->isUnder($parentReal, $public)) {
                throw LocalSealedException::failClosed('unseal_key_inside_web_root');
            }
            if ($parentReal !== false && $store !== false && $this->isUnder($parentReal, $store)) {
                throw LocalSealedException::failClosed('unseal_key_inside_store');
            }

            return;
        }

        if ($public !== false && $this->isUnder($realKey, $public)) {
            throw LocalSealedException::failClosed('unseal_key_inside_web_root');
        }

        if ($store !== false && $this->isUnder($realKey, $store)) {
            throw LocalSealedException::failClosed('unseal_key_inside_store');
        }
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if ($this->fs->isLink($path)) {
            throw LocalSealedException::failClosed('unseal_key_symlink');
        }

        $current = $path;
        for ($i = 0; $i < 64; $i++) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            if ($this->fs->isLink($parent)) {
                throw LocalSealedException::failClosed('unseal_key_symlink');
            }
            $current = $parent;
        }
    }

    private function isUnder(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
